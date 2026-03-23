param(
	[ValidateSet( 'start', 'install', 'stop', 'reset', 'logs', 'shell', 'wp' )]
	[string]$Command = 'start',
	[Parameter( ValueFromRemainingArguments = $true )]
	[string[]]$Args
)

$repoRoot = Split-Path -Parent $PSScriptRoot
$envPath  = Join-Path $repoRoot '.env'
$defaults = @{
	WORDPRESS_URL            = 'http://localhost:8888'
	WORDPRESS_TITLE          = 'Flavor Agent Local'
	WORDPRESS_ADMIN_USER     = 'admin'
	WORDPRESS_ADMIN_PASSWORD = 'admin'
	WORDPRESS_ADMIN_EMAIL    = 'admin@example.com'
}

function Ensure-DockerReady {
	$dockerCommand = Get-Command docker -ErrorAction SilentlyContinue
	if ( -not $dockerCommand ) {
		throw 'Docker CLI was not found on PATH. Install Docker Desktop, restart your shell, and rerun this command.'
	}

	& docker compose version *> $null
	if ( $LASTEXITCODE -ne 0 ) {
		throw 'Docker Compose is not available from the Docker CLI. Install or update Docker Desktop so `docker compose` works, then rerun this command.'
	}

	& docker info *> $null
	if ( $LASTEXITCODE -ne 0 ) {
		throw 'Docker is installed, but the daemon is not reachable. Start Docker Desktop and wait for the engine to finish starting, then rerun this command.'
	}
}

function Get-EnvValue {
	param(
		[string]$Name
	)

	if ( Test-Path $envPath ) {
		foreach ( $line in Get-Content $envPath ) {
			if ( $line -match '^\s*#' -or $line -notmatch '=' ) {
				continue
			}

			$key, $value = $line -split '=', 2
			if ( $key.Trim() -eq $Name ) {
				return $value.Trim().Trim( '"' ).Trim( "'" )
			}
		}
	}

	return $defaults[ $Name ]
}

function Ensure-EnvFile {
	if ( Test-Path $envPath ) {
		return
	}

	$examplePath = Join-Path $repoRoot '.env.example'
	if ( Test-Path $examplePath ) {
		Copy-Item $examplePath $envPath
		Write-Host 'Created .env from .env.example'
	}
}

function Invoke-Compose {
	param(
		[string[]]$ComposeArgs
	)

	Push-Location $repoRoot
	try {
		& docker compose @ComposeArgs
		if ( $LASTEXITCODE -ne 0 ) {
			exit $LASTEXITCODE
		}
	} finally {
		Pop-Location
	}
}

function Wait-ForWordPressCli {
	for ( $attempt = 0; $attempt -lt 30; $attempt++ ) {
		Push-Location $repoRoot
		try {
			& docker compose exec -T wordpress wp core version --allow-root *> $null
			if ( $LASTEXITCODE -eq 0 ) {
				return
			}
		} finally {
			Pop-Location
		}

		Start-Sleep -Seconds 3
	}

	throw 'WordPress container did not become ready in time.'
}

Ensure-DockerReady
Ensure-EnvFile

switch ( $Command ) {
	'start' {
		Invoke-Compose -ComposeArgs @( 'up', '-d', '--build' )
	}

	'install' {
		Invoke-Compose -ComposeArgs @( 'up', '-d', '--build' )
		Wait-ForWordPressCli

		$url           = Get-EnvValue -Name 'WORDPRESS_URL'
		$title         = Get-EnvValue -Name 'WORDPRESS_TITLE'
		$adminUser     = Get-EnvValue -Name 'WORDPRESS_ADMIN_USER'
		$adminPassword = Get-EnvValue -Name 'WORDPRESS_ADMIN_PASSWORD'
		$adminEmail    = Get-EnvValue -Name 'WORDPRESS_ADMIN_EMAIL'

		Push-Location $repoRoot
		try {
			& docker compose exec -T wordpress wp core is-installed --allow-root *> $null
			if ( $LASTEXITCODE -ne 0 ) {
				& docker compose exec -T wordpress wp core install "--url=$url" "--title=$title" "--admin_user=$adminUser" "--admin_password=$adminPassword" "--admin_email=$adminEmail" --skip-email --allow-root
				if ( $LASTEXITCODE -ne 0 ) {
					exit $LASTEXITCODE
				}
			}

			& docker compose exec -T wordpress wp plugin activate flavor-agent --allow-root
			if ( $LASTEXITCODE -ne 0 ) {
				exit $LASTEXITCODE
			}

			& docker compose exec -T wordpress wp rewrite structure '/%postname%/' --hard --allow-root
			if ( $LASTEXITCODE -ne 0 ) {
				exit $LASTEXITCODE
			}
		} finally {
			Pop-Location
		}
	}

	'stop' {
		Invoke-Compose -ComposeArgs @( 'down' )
	}

	'reset' {
		Invoke-Compose -ComposeArgs @( 'down', '-v' )
	}

	'logs' {
		Invoke-Compose -ComposeArgs @( 'logs', '-f' )
	}

	'shell' {
		Invoke-Compose -ComposeArgs @( 'exec', 'wordpress', 'bash' )
	}

	'wp' {
		if ( $Args.Count -eq 0 ) {
			throw 'Pass the WP-CLI command after `wp`, for example: .\scripts\local-wordpress.ps1 wp plugin list'
		}

		Invoke-Compose -ComposeArgs ( @( 'exec', 'wordpress', 'wp' ) + $Args + @( '--allow-root' ) )
	}
}
