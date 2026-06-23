const pptxgen = require("pptxgenjs");
const React = require("react");
const ReactDOMServer = require("react-dom/server");
const sharp = require("sharp");

// Icon imports
const { FaUsers, FaComments, FaLightbulb, FaClipboardList, FaShieldAlt, FaCheckCircle, FaTimesCircle, FaArrowRight, FaRobot, FaHandshake, FaQuestionCircle, FaFileAlt, FaSlack, FaEye, FaUserCheck, FaClock, FaEdit, FaShareAlt } = require("react-icons/fa");

// Color palette (no #)
const COLORS = {
  bg: "FAF6EC",
  card: "F5EFE1",
  text: "1B231D",
  primary: "2E4A3A",      // dark forest green
  secondary: "3F6E89",    // slate teal
  highlight: "C29A44",    // muted gold
  white: "FFFFFF",
  muted: "6B7B74",
  lightAccent: "E8E0D0",
};

// Helper to render icons
function renderIconSvg(IconComponent, color, size = 256) {
  return ReactDOMServer.renderToStaticMarkup(
    React.createElement(IconComponent, { color, size: String(size) })
  );
}

async function iconToBase64Png(IconComponent, color, size = 256) {
  const svg = renderIconSvg(IconComponent, color, size);
  const pngBuffer = await sharp(Buffer.from(svg)).png().toBuffer();
  return "image/png;base64," + pngBuffer.toString("base64");
}

async function createPresentation() {
  const pres = new pptxgen();
  pres.layout = "LAYOUT_16x9";
  pres.author = "Henry Perkins / Cohort";
  pres.title = "Claude + Slack: Practical AI Workflows for Community Teams";
  pres.subject = "Short lesson for learning cohort";

  // Pre-render icons
  const iconUsers = await iconToBase64Png(FaUsers, "#" + COLORS.primary, 256);
  const iconComments = await iconToBase64Png(FaComments, "#" + COLORS.primary, 256);
  const iconLightbulb = await iconToBase64Png(FaLightbulb, "#" + COLORS.highlight, 256);
  const iconClipboard = await iconToBase64Png(FaClipboardList, "#" + COLORS.secondary, 256);
  const iconShield = await iconToBase64Png(FaShieldAlt, "#" + COLORS.primary, 256);
  const iconCheck = await iconToBase64Png(FaCheckCircle, "#" + COLORS.primary, 256);
  const iconTimes = await iconToBase64Png(FaTimesCircle, "#" + "A85C5C", 256);
  const iconArrow = await iconToBase64Png(FaArrowRight, "#" + COLORS.secondary, 256);
  const iconRobot = await iconToBase64Png(FaRobot, "#" + COLORS.secondary, 256);
  const iconHandshake = await iconToBase64Png(FaHandshake, "#" + COLORS.primary, 256);
  const iconQuestion = await iconToBase64Png(FaQuestionCircle, "#" + COLORS.highlight, 256);
  const iconFile = await iconToBase64Png(FaFileAlt, "#" + COLORS.secondary, 256);
  const iconEdit = await iconToBase64Png(FaEdit, "#" + COLORS.primary, 256);
  const iconShare = await iconToBase64Png(FaShareAlt, "#" + COLORS.secondary, 256);
  const iconEye = await iconToBase64Png(FaEye, "#" + COLORS.primary, 256);
  const iconClock = await iconToBase64Png(FaClock, "#" + COLORS.highlight, 256);

  // ============================================
  // SLIDE 1: Title
  // ============================================
  let slide = pres.addSlide();
  slide.background = { color: COLORS.bg };

  // Large title
  slide.addText("Claude + Slack", {
    x: 0.7, y: 1.4, w: 8.6, h: 0.9,
    fontSize: 52, fontFace: "Arial", bold: true,
    color: COLORS.primary, margin: 0
  });
  slide.addText("Practical AI Workflows for Community Teams", {
    x: 0.7, y: 2.25, w: 8.6, h: 0.65,
    fontSize: 26, fontFace: "Arial",
    color: COLORS.text, margin: 0
  });

  // Sub
  slide.addText("A short lesson and live demo grown from our Slack thread", {
    x: 0.7, y: 3.15, w: 8.6, h: 0.45,
    fontSize: 16, fontFace: "Arial", italic: true,
    color: COLORS.muted, margin: 0
  });

  // Decorative accent bar
  slide.addShape(pres.shapes.RECTANGLE, {
    x: 0.7, y: 3.75, w: 1.8, h: 0.06,
    fill: { color: COLORS.highlight }, line: { color: COLORS.highlight }
  });

  // Timing info box
  slide.addShape(pres.shapes.ROUNDED_RECTANGLE, {
    x: 0.7, y: 4.2, w: 5.8, h: 1.0,
    fill: { color: COLORS.card },
    rectRadius: 0.08
  });
  slide.addText([
    { text: "Today: ~15 minutes  ", options: { bold: true, color: COLORS.primary } },
    { text: "• 2 min intro  • 5 min demos  • 5 min practice  • 3 min discussion", options: { color: COLORS.text } }
  ], {
    x: 0.9, y: 4.35, w: 5.4, h: 0.7,
    fontSize: 14, fontFace: "Arial", margin: 0, valign: "middle"
  });

  // Small cohort note
  slide.addText("For our learning cohort • Peer-to-peer, practical, exploratory", {
    x: 0.7, y: 5.3, w: 8.6, h: 0.25,
    fontSize: 11, fontFace: "Arial",
    color: COLORS.muted, margin: 0
  });

  slide.addNotes("PRESENTER NOTES — SLIDE 1 (Title)\n\n" +
    "Timing: 30–60 seconds max for intro.\n" +
    "Welcome everyone. Mention this came directly from the Slack thread Henry posted.\n" +
    "Say: 'We had clear interest, so let's spend a short time exploring together — no hype, just practical patterns we can actually use.'\n" +
    "Point to the timing so people know what to expect.\n" +
    "Transition: 'Let's start with where the idea came from…'\n");

  // ============================================
  // SLIDE 2: Why this lesson (Slack thread origin)
  // ============================================
  slide = pres.addSlide();
  slide.background = { color: COLORS.bg };

  slide.addText("Why this lesson", {
    x: 0.7, y: 0.35, w: 8.6, h: 0.55,
    fontSize: 32, fontFace: "Arial", bold: true,
    color: COLORS.primary, margin: 0
  });
  slide.addText("It started with a simple question in our channel", {
    x: 0.7, y: 0.85, w: 8.6, h: 0.35,
    fontSize: 15, fontFace: "Arial", italic: true,
    color: COLORS.muted, margin: 0
  });

  // Henry question card
  slide.addShape(pres.shapes.ROUNDED_RECTANGLE, {
    x: 0.7, y: 1.35, w: 8.6, h: 1.05,
    fill: { color: COLORS.card },
    rectRadius: 0.08
  });
  slide.addText([
    { text: "Henry:  ", options: { bold: true, color: COLORS.primary } },
    { text: '"Anyone interested in a short lesson on how Claude can work with communication tools like Slack? Using shared context, prompts, summaries, and lightweight automations for community workflows."', options: { color: COLORS.text } }
  ], {
    x: 0.9, y: 1.45, w: 8.2, h: 0.85,
    fontSize: 14, fontFace: "Arial", margin: 0, valign: "middle"
  });

  // Replies grid (4 people)
  const replies = [
    { name: "Stephen", text: "“Interested. Run it.”" },
    { name: "Nickholas", text: "“Would love a demo.”" },
    { name: "Diego", text: "“I can add how I use chat + Claude with WordPress.”" },
    { name: "Michael", text: "“Yes, please.”" }
  ];

  replies.forEach((r, i) => {
    const x = 0.7 + (i % 2) * 4.4;
    const y = 2.6 + Math.floor(i / 2) * 1.15;
    slide.addShape(pres.shapes.ROUNDED_RECTANGLE, {
      x, y, w: 4.15, h: 1.0,
      fill: { color: COLORS.card },
      rectRadius: 0.08
    });
    slide.addImage({ data: iconUsers, x: x + 0.15, y: y + 0.25, w: 0.38, h: 0.38 });
    slide.addText([
      { text: r.name + ":  ", options: { bold: true, color: COLORS.secondary } },
      { text: r.text, options: { color: COLORS.text } }
    ], {
      x: x + 0.65, y: y + 0.2, w: 3.35, h: 0.6,
      fontSize: 13.5, fontFace: "Arial", margin: 0, valign: "middle"
    });
  });

  slide.addNotes("PRESENTER NOTES — SLIDE 2\n\n" +
    "Timing: 60–75 seconds.\n" +
    "Read Henry's original question briefly.\n" +
    "Point to each reply: 'Stephen said run it, Nick wanted a demo, Diego offered to share WordPress workflows, Michael said yes please.'\n" +
    "Say: 'This isn't something imposed from outside. It came from inside the cohort. That's why we're keeping it grounded and practical.'\n" +
    "Transition: 'So what is the actual idea here?'\n");

  // ============================================
  // SLIDE 3: The core idea
  // ============================================
  slide = pres.addSlide();
  slide.background = { color: COLORS.bg };

  slide.addText("The core idea", {
    x: 0.7, y: 0.35, w: 8.6, h: 0.55,
    fontSize: 32, fontFace: "Arial", bold: true,
    color: COLORS.primary, margin: 0
  });

  slide.addText("AI helps most when it works with shared context — not isolated one-off prompts.", {
    x: 0.7, y: 0.9, w: 8.6, h: 0.5,
    fontSize: 17, fontFace: "Arial",
    color: COLORS.text, margin: 0
  });

  // Two comparison cards
  // Left: Isolated
  slide.addShape(pres.shapes.ROUNDED_RECTANGLE, {
    x: 0.7, y: 1.6, w: 4.15, h: 2.9,
    fill: { color: COLORS.card },
    rectRadius: 0.08
  });
  slide.addText("Isolated prompt", {
    x: 0.9, y: 1.75, w: 3.75, h: 0.4,
    fontSize: 15, fontFace: "Arial", bold: true, color: COLORS.muted, margin: 0
  });
  slide.addText('"Summarize this thread for me."', {
    x: 0.9, y: 2.2, w: 3.75, h: 0.7,
    fontSize: 13, fontFace: "Consolas", italic: true, color: COLORS.text, margin: 0
  });
  slide.addText("Results are often generic.\nMisses tone, history, who said what, what matters to the group.", {
    x: 0.9, y: 2.95, w: 3.75, h: 1.3,
    fontSize: 13, fontFace: "Arial", color: COLORS.muted, margin: 0
  });

  // Right: With context
  slide.addShape(pres.shapes.ROUNDED_RECTANGLE, {
    x: 5.15, y: 1.6, w: 4.15, h: 2.9,
    fill: { color: COLORS.card },
    rectRadius: 0.08
  });
  slide.addText("Shared context + prompt", {
    x: 5.35, y: 1.75, w: 3.75, h: 0.4,
    fontSize: 15, fontFace: "Arial", bold: true, color: COLORS.primary, margin: 0
  });
  slide.addText("Paste the thread + state the goal, constraints, and desired shape of the output.", {
    x: 5.35, y: 2.2, w: 3.75, h: 0.9,
    fontSize: 13, fontFace: "Arial", color: COLORS.text, margin: 0
  });
  slide.addText("Output becomes useful to the actual group.\nYou stay in control of what gets shared.", {
    x: 5.35, y: 3.15, w: 3.75, h: 1.1,
    fontSize: 13, fontFace: "Arial", color: COLORS.text, margin: 0
  });

  // Bottom insight
  slide.addShape(pres.shapes.ROUNDED_RECTANGLE, {
    x: 0.7, y: 4.7, w: 8.6, h: 0.7,
    fill: { color: COLORS.primary },
    rectRadius: 0.08
  });
  slide.addText("The magic isn't the model. It's the context you choose to give it + the review you keep.", {
    x: 0.9, y: 4.82, w: 8.2, h: 0.45,
    fontSize: 15, fontFace: "Arial", bold: true, color: COLORS.white, margin: 0, valign: "middle", align: "center"
  });

  slide.addNotes("PRESENTER NOTES — SLIDE 3\n\n" +
    "Timing: 60–75 seconds.\n" +
    "Key line: 'The difference isn't better prompts alone — it's feeding the AI the actual shared conversation instead of starting from zero every time.'\n" +
    "Emphasize: you are still the one deciding what context to share and what to do with the output.\n" +
    "Transition: 'What kinds of community work does this actually help with?'\n");

  // ============================================
  // SLIDE 4: Workflow opportunities
  // ============================================
  slide = pres.addSlide();
  slide.background = { color: COLORS.bg };

  slide.addText("Community workflow opportunities", {
    x: 0.7, y: 0.35, w: 8.6, h: 0.55,
    fontSize: 28, fontFace: "Arial", bold: true,
    color: COLORS.primary, margin: 0
  });
  slide.addText("Small, repeatable places where a bit of shared context + Claude saves time and keeps things clear.", {
    x: 0.7, y: 0.85, w: 8.6, h: 0.4,
    fontSize: 14, fontFace: "Arial", italic: true,
    color: COLORS.muted, margin: 0
  });

  const opportunities = [
    { icon: iconComments, title: "Thread summaries", desc: "Messy back-and-forth → decisions, open questions, next actions" },
    { icon: iconClock, title: "Catch-up recaps", desc: "Missed a week? Quick digest of the important bits." },
    { icon: iconClipboard, title: "Decision logs", desc: "Turn discussions into a lightweight shared record." },
    { icon: iconFile, title: "Meeting / event prep", desc: "Draft agendas, facilitation guides, or pre-reads from prior notes." },
    { icon: iconLightbulb, title: "Prompt reuse", desc: "Capture a good prompt once, reuse and adapt it." },
    { icon: iconHandshake, title: "Lightweight checks", desc: "Reminders, status nudges, or draft replies — always with human review." }
  ];

  opportunities.forEach((o, i) => {
    const col = i % 3;
    const row = Math.floor(i / 3);
    const x = 0.7 + col * 3.05;
    const y = 1.45 + row * 1.85;

    slide.addShape(pres.shapes.ROUNDED_RECTANGLE, {
      x, y, w: 2.9, h: 1.7,
      fill: { color: COLORS.card },
      rectRadius: 0.08
    });
    slide.addImage({ data: o.icon, x: x + 0.18, y: y + 0.18, w: 0.42, h: 0.42 });
    slide.addText(o.title, {
      x: x + 0.7, y: y + 0.2, w: 2.0, h: 0.38,
      fontSize: 14, fontFace: "Arial", bold: true, color: COLORS.text, margin: 0
    });
    slide.addText(o.desc, {
      x: x + 0.18, y: y + 0.7, w: 2.54, h: 0.85,
      fontSize: 12, fontFace: "Arial", color: COLORS.muted, margin: 0
    });
  });

  slide.addNotes("PRESENTER NOTES — SLIDE 4\n\n" +
    "Timing: ~75 seconds. Don't read every card.\n" +
    "Pick 2-3 to highlight quickly: 'For example, instead of everyone scrolling back through 60 messages, we get a clean capture of decisions and actions.'\n" +
    "Say the key qualifier: 'All of these still have a human deciding what to keep and how to phrase the final version.'\n" +
    "Transition: 'Before we look at examples, let's be explicit about what this is not.'\n");

  // ============================================
  // SLIDE 5: What this is not + good vs risky
  // ============================================
  slide = pres.addSlide();
  slide.background = { color: COLORS.bg };

  slide.addText("What this is not", {
    x: 0.7, y: 0.35, w: 8.6, h: 0.5,
    fontSize: 28, fontFace: "Arial", bold: true,
    color: COLORS.primary, margin: 0
  });

  const nots = [
    { icon: iconTimes, text: "Replacing people — community still runs on humans" },
    { icon: iconTimes, text: "Auto-posting without review — every draft is edited by a person" },
    { icon: iconTimes, text: "Surveillance — we only use public channels and explicit consent" },
    { icon: iconTimes, text: "Magic shared memory — AI forgets everything between sessions" }
  ];

  nots.forEach((n, i) => {
    const y = 1.0 + i * 0.72;
    slide.addShape(pres.shapes.ROUNDED_RECTANGLE, {
      x: 0.7, y, w: 8.6, h: 0.62,
      fill: { color: COLORS.card },
      rectRadius: 0.06
    });
    slide.addImage({ data: n.icon, x: 0.9, y: y + 0.12, w: 0.36, h: 0.36 });
    slide.addText(n.text, {
      x: 1.4, y: y + 0.12, w: 7.6, h: 0.4,
      fontSize: 14.5, fontFace: "Arial", color: COLORS.text, margin: 0, valign: "middle"
    });
  });

  // Good vs Risky comparison
  slide.addText("Good use vs risky use", {
    x: 0.7, y: 3.95, w: 8.6, h: 0.35,
    fontSize: 15, fontFace: "Arial", bold: true, color: COLORS.primary, margin: 0
  });

  // Good card
  slide.addShape(pres.shapes.ROUNDED_RECTANGLE, {
    x: 0.7, y: 4.35, w: 4.15, h: 1.05,
    fill: { color: COLORS.card },
    rectRadius: 0.06
  });
  slide.addImage({ data: iconCheck, x: 0.85, y: 4.5, w: 0.32, h: 0.32 });
  slide.addText([
    { text: "Good: ", options: { bold: true, color: COLORS.primary } },
    { text: "Draft a summary of a public planning thread. Post it with \"Here's a quick capture — please add anything I missed.\"", options: { color: COLORS.text } }
  ], {
    x: 1.25, y: 4.45, w: 3.45, h: 0.85,
    fontSize: 11.5, fontFace: "Arial", margin: 0
  });

  // Risky card
  slide.addShape(pres.shapes.ROUNDED_RECTANGLE, {
    x: 5.15, y: 4.35, w: 4.15, h: 1.05,
    fill: { color: COLORS.card },
    rectRadius: 0.06
  });
  slide.addImage({ data: iconTimes, x: 5.3, y: 4.5, w: 0.32, h: 0.32 });
  slide.addText([
    { text: "Risky: ", options: { bold: true, color: "A85C5C" } },
    { text: "Auto-replying in channels or summarizing private DMs without asking the people involved.", options: { color: COLORS.text } }
  ], {
    x: 5.7, y: 4.45, w: 3.45, h: 0.85,
    fontSize: 11.5, fontFace: "Arial", margin: 0
  });

  slide.addNotes("PRESENTER NOTES — SLIDE 5\n\n" +
    "Timing: ~60 seconds. Read the four 'nots' quickly.\n" +
    "Pause on the last one: 'It doesn't have memory. Every time you want it to be useful you give it the relevant pieces.'\n" +
    "Point to the good/risky example: 'This distinction matters. We want the helpful side without creeping into the risky side.'\n" +
    "Transition: 'Let's look at a concrete example of turning a messy thread into something useful.'\n");

  // ============================================
  // SLIDE 6: Demo 1 - Summarize thread
  // ============================================
  slide = pres.addSlide();
  slide.background = { color: COLORS.bg };

  slide.addText("Demo 1", {
    x: 0.7, y: 0.25, w: 8.6, h: 0.4,
    fontSize: 14, fontFace: "Arial", bold: true, color: COLORS.secondary, margin: 0
  });
  slide.addText("Summarize a messy thread into decisions + actions", {
    x: 0.7, y: 0.55, w: 8.6, h: 0.45,
    fontSize: 22, fontFace: "Arial", bold: true,
    color: COLORS.primary, margin: 0
  });

  // Left: Sample thread (fictional)
  slide.addText("Sample Slack thread (fictional)", {
    x: 0.7, y: 1.05, w: 4.2, h: 0.3,
    fontSize: 11, fontFace: "Arial", bold: true, color: COLORS.muted, margin: 0
  });

  const threadLines = [
    { n: "Henry", m: "Anyone want to do a skill-share session next month? 3–4 people, 10 min each?" },
    { n: "Stephen", m: "I'm in. I can share my prompt library for research." },
    { n: "Nick", m: "+1. Should we also do an async version for people who can't join live?" },
    { n: "Diego", m: "Happy to show some Claude + WordPress stuff if that fits." },
    { n: "Michael", m: "Yes please. I need better ideas for onboarding new cohort members." },
    { n: "Henry", m: "Great. Live or recorded? Date ideas? Let's lock something in this week." }
  ];

  threadLines.forEach((t, i) => {
    const y = 1.38 + i * 0.52;
    slide.addShape(pres.shapes.ROUNDED_RECTANGLE, {
      x: 0.7, y, w: 4.2, h: 0.48,
      fill: { color: i % 2 === 0 ? COLORS.card : COLORS.lightAccent },
      rectRadius: 0.04
    });
    slide.addText([
      { text: t.n + ": ", options: { bold: true, color: COLORS.primary } },
      { text: t.m, options: { color: COLORS.text } }
    ], {
      x: 0.82, y: y + 0.05, w: 3.95, h: 0.38,
      fontSize: 10, fontFace: "Arial", margin: 0, valign: "middle"
    });
  });

  // Right: Structured output
  slide.addText("Claude output (structured)", {
    x: 5.15, y: 1.05, w: 4.2, h: 0.3,
    fontSize: 11, fontFace: "Arial", bold: true, color: COLORS.muted, margin: 0
  });

  slide.addShape(pres.shapes.ROUNDED_RECTANGLE, {
    x: 5.15, y: 1.35, w: 4.15, h: 3.0,
    fill: { color: COLORS.card },
    rectRadius: 0.08
  });

  slide.addText([
    { text: "Decisions\n", options: { bold: true, color: COLORS.primary, breakLine: true } },
    { text: "• Run a skill-share session in the next 3 weeks\n• 3–4 short shares, mix of live + async option\n", options: { color: COLORS.text } },
    { text: "\nOpen questions\n", options: { bold: true, color: COLORS.primary, breakLine: true } },
    { text: "• Live only or also recorded/pre-recorded?\n• Exact date and time\n", options: { color: COLORS.text } },
    { text: "\nNext actions\n", options: { bold: true, color: COLORS.primary, breakLine: true } },
    { text: "• Henry: post a poll for dates this week\n• Diego: outline the WP segment\n• Stephen: prep prompt library share\n• Everyone: suggest other topics", options: { color: COLORS.text } }
  ], {
    x: 5.3, y: 1.5, w: 3.85, h: 2.7,
    fontSize: 11, fontFace: "Arial", margin: 0
  });

  // Prompt used
  slide.addShape(pres.shapes.ROUNDED_RECTANGLE, {
    x: 0.7, y: 4.5, w: 8.6, h: 0.9,
    fill: { color: "EDE6D8" },
    rectRadius: 0.06
  });
  slide.addText("Prompt used (concise version):", {
    x: 0.85, y: 4.55, w: 8.3, h: 0.22,
    fontSize: 10, fontFace: "Arial", bold: true, color: COLORS.secondary, margin: 0
  });
  slide.addText("You are a helpful cohort facilitator. Summarize the thread into: 1. Decisions 2. Open questions 3. Next actions (with owners). Keep bullets short and actionable. Note who raised what when useful.", {
    x: 0.85, y: 4.78, w: 8.3, h: 0.55,
    fontSize: 11, fontFace: "Consolas", color: COLORS.text, margin: 0
  });

  slide.addNotes("PRESENTER NOTES — SLIDE 6 (Demo 1)\n\n" +
    "Timing: This is the start of the 5 min demo block.\n" +
    "Live action: Open Claude (or your tool), paste the fictional thread (or a real public one if you have permission), use a prompt close to the one shown.\n" +
    "Walk through the output: 'See how it pulled decisions, left questions open, and assigned next actions with names?'\n" +
    "Say: 'I didn't ask it to be clever. I gave it the context and told it the shape I wanted.'\n" +
    "Transition: 'What if we want to turn the discussion itself into something reusable?'\n");

  // ============================================
  // SLIDE 7: Demo 2 - Reusable prompt/guide
  // ============================================
  slide = pres.addSlide();
  slide.background = { color: COLORS.bg };

  slide.addText("Demo 2", {
    x: 0.7, y: 0.25, w: 8.6, h: 0.4,
    fontSize: 14, fontFace: "Arial", bold: true, color: COLORS.secondary, margin: 0
  });
  slide.addText("Turn discussion into a reusable prompt or facilitation guide", {
    x: 0.7, y: 0.55, w: 8.6, h: 0.45,
    fontSize: 20, fontFace: "Arial", bold: true,
    color: COLORS.primary, margin: 0
  });

  // Left input
  slide.addShape(pres.shapes.ROUNDED_RECTANGLE, {
    x: 0.7, y: 1.15, w: 4.15, h: 2.55,
    fill: { color: COLORS.card },
    rectRadius: 0.08
  });
  slide.addText("From the thread...", {
    x: 0.85, y: 1.25, w: 3.85, h: 0.28,
    fontSize: 11, fontFace: "Arial", bold: true, color: COLORS.muted, margin: 0
  });
  slide.addText("People mentioned wanting better onboarding, prompt libraries, and WP examples.\n\nSomeone asked for an async option.\n\nThe group wants short, practical shares.", {
    x: 0.85, y: 1.55, w: 3.85, h: 2.0,
    fontSize: 12, fontFace: "Arial", color: COLORS.text, margin: 0
  });

  slide.addImage({ data: iconArrow, x: 4.95, y: 2.2, w: 0.35, h: 0.35 });

  // Right output
  slide.addShape(pres.shapes.ROUNDED_RECTANGLE, {
    x: 5.4, y: 1.15, w: 4.0, h: 2.55,
    fill: { color: COLORS.card },
    rectRadius: 0.08
  });
  slide.addText("Reusable facilitation starter", {
    x: 5.55, y: 1.25, w: 3.7, h: 0.28,
    fontSize: 11, fontFace: "Arial", bold: true, color: COLORS.primary, margin: 0
  });
  slide.addText("Prompt for future skill-share organizers:\n\n\"You are helping plan a 45-min cohort skill share. Suggest 3-4 topics based on recent discussion. Include one async option. Keep each slot to 8-10 min. List who might be good to invite and why.\"", {
    x: 5.55, y: 1.55, w: 3.7, h: 2.0,
    fontSize: 11, fontFace: "Arial", color: COLORS.text, margin: 0
  });

  // Bottom note
  slide.addShape(pres.shapes.ROUNDED_RECTANGLE, {
    x: 0.7, y: 3.9, w: 8.6, h: 1.5,
    fill: { color: COLORS.card },
    rectRadius: 0.08
  });
  slide.addText("Why this is useful", {
    x: 0.9, y: 4.0, w: 8.2, h: 0.3,
    fontSize: 13, fontFace: "Arial", bold: true, color: COLORS.primary, margin: 0
  });
  slide.addText("Instead of starting from a blank page every time someone wants to run a similar session, the group now has a living starter prompt. It encodes what the cohort actually cares about (short, practical, mix of live + async, relevant topics). Next organizer just adds fresh context.", {
    x: 0.9, y: 4.3, w: 8.2, h: 1.0,
    fontSize: 12.5, fontFace: "Arial", color: COLORS.text, margin: 0
  });

  slide.addNotes("PRESENTER NOTES — SLIDE 7 (Demo 2)\n\n" +
    "Live: Take the same thread or a different one and ask Claude to 'extract a reusable prompt template for the next person who runs this kind of thing.'\n" +
    "Highlight that the output is not 'the answer' — it's a starting scaffold that the next human improves.\n" +
    "Say: 'This is how we slowly build a small library of cohort-specific prompts without a lot of ceremony.'\n" +
    "Transition: 'What about turning a discussion into a suggested automation?'\n");

  // ============================================
  // SLIDE 8: Demo 3 - Lightweight automation
  // ============================================
  slide = pres.addSlide();
  slide.background = { color: COLORS.bg };

  slide.addText("Demo 3", {
    x: 0.7, y: 0.25, w: 8.6, h: 0.4,
    fontSize: 14, fontFace: "Arial", bold: true, color: COLORS.secondary, margin: 0
  });
  slide.addText("Propose a lightweight automation idea — with human review", {
    x: 0.7, y: 0.55, w: 8.6, h: 0.45,
    fontSize: 20, fontFace: "Arial", bold: true,
    color: COLORS.primary, margin: 0
  });

  // Idea card
  slide.addShape(pres.shapes.ROUNDED_RECTANGLE, {
    x: 0.7, y: 1.15, w: 8.6, h: 1.7,
    fill: { color: COLORS.card },
    rectRadius: 0.08
  });
  slide.addText("Idea (proposed by Claude after seeing the thread)", {
    x: 0.9, y: 1.25, w: 8.2, h: 0.3,
    fontSize: 12, fontFace: "Arial", bold: true, color: COLORS.muted, margin: 0
  });
  slide.addText("After a skill-share thread wraps up, suggest a short recap message that the organizer can post. Include: 1) 2-3 key takeaways from each share 2) Links or resources mentioned 3) Next date poll. The organizer pastes the thread, gets a draft, edits, and posts.", {
    x: 0.9, y: 1.55, w: 8.2, h: 1.15,
    fontSize: 13, fontFace: "Arial", color: COLORS.text, margin: 0
  });

  // Review emphasis
  slide.addShape(pres.shapes.ROUNDED_RECTANGLE, {
    x: 0.7, y: 3.05, w: 8.6, h: 1.0,
    fill: { color: COLORS.primary },
    rectRadius: 0.08
  });
  slide.addText("Always: Human reviews, edits, and posts. The automation only proposes.", {
    x: 0.9, y: 3.2, w: 8.2, h: 0.7,
    fontSize: 15, fontFace: "Arial", bold: true, color: COLORS.white, margin: 0, valign: "middle", align: "center"
  });

  // Benefits
  slide.addText("What makes this lightweight and safe", {
    x: 0.7, y: 4.2, w: 8.6, h: 0.3,
    fontSize: 13, fontFace: "Arial", bold: true, color: COLORS.primary, margin: 0
  });

  const benefits = [
    "Only runs when a human triggers it",
    "Output is a draft, never auto-published",
    "Easy to ignore or heavily rewrite",
    "No credentials or channel posting rights needed"
  ];

  benefits.forEach((b, i) => {
    const x = 0.7 + (i % 2) * 4.5;
    const y = 4.55 + Math.floor(i / 2) * 0.42;
    slide.addImage({ data: iconCheck, x, y: y + 0.02, w: 0.26, h: 0.26 });
    slide.addText(b, {
      x: x + 0.35, y, w: 4.0, h: 0.38,
      fontSize: 12, fontFace: "Arial", color: COLORS.text, margin: 0, valign: "middle"
    });
  });

  slide.addNotes("PRESENTER NOTES — SLIDE 8 (Demo 3)\n\n" +
    "Live demo idea: Ask Claude 'Looking at this thread, suggest a very lightweight automation that could help future organizers without taking over any posting.'\n" +
    "Emphasize the 'human in the loop' part heavily.\n" +
    "Say: 'The value here is speed of drafting + consistency of structure. The human still owns voice and accuracy.'\n" +
    "Transition: 'All three demos used the same basic pattern. Let's name it.'\n");

  // ============================================
  // SLIDE 9: Shared context pattern + diagram + template
  // ============================================
  slide = pres.addSlide();
  slide.background = { color: COLORS.bg };

  slide.addText("The shared context pattern", {
    x: 0.7, y: 0.25, w: 8.6, h: 0.45,
    fontSize: 24, fontFace: "Arial", bold: true,
    color: COLORS.primary, margin: 0
  });

  // Workflow diagram - 5 steps horizontal
  const steps = [
    { num: "1", label: "Slack context", sub: "Paste relevant\nmessages or notes" },
    { num: "2", label: "Prompt + pattern", sub: "Goal + constraints\n+ output shape" },
    { num: "3", label: "Structured output", sub: "Decisions, actions,\nguide, draft..." },
    { num: "4", label: "Human review", sub: "Edit, add nuance,\ncheck accuracy" },
    { num: "5", label: "Shared artifact", sub: "Post, save, or\nact on it" }
  ];

  steps.forEach((s, i) => {
    const x = 0.55 + i * 1.88;
    // Circle
    slide.addShape(pres.shapes.OVAL, {
      x: x + 0.55, y: 0.85, w: 0.52, h: 0.52,
      fill: { color: i === 4 ? COLORS.highlight : COLORS.primary }
    });
    slide.addText(s.num, {
      x: x + 0.55, y: 0.88, w: 0.52, h: 0.48,
      fontSize: 16, fontFace: "Arial", bold: true, color: COLORS.white, margin: 0, align: "center", valign: "middle"
    });
    // Box
    slide.addShape(pres.shapes.ROUNDED_RECTANGLE, {
      x, y: 1.45, w: 1.7, h: 1.15,
      fill: { color: COLORS.card },
      rectRadius: 0.06
    });
    slide.addText(s.label, {
      x: x + 0.08, y: 1.52, w: 1.54, h: 0.35,
      fontSize: 11, fontFace: "Arial", bold: true, color: COLORS.text, margin: 0, align: "center"
    });
    slide.addText([
      { text: s.sub.split('\n')[0] || '', options: { breakLine: true } },
      { text: s.sub.split('\n')[1] || '' }
    ], {
      x: x + 0.08, y: 1.88, w: 1.54, h: 0.65,
      fontSize: 9.5, fontFace: "Arial", color: COLORS.muted, margin: 0, align: "center"
    });

    // Arrow between (except last)
    if (i < 4) {
      slide.addImage({ data: iconArrow, x: x + 1.68, y: 1.85, w: 0.22, h: 0.22 });
    }
  });

  // Reusable template
  slide.addText("Reusable prompt template (copy this)", {
    x: 0.7, y: 2.8, w: 8.6, h: 0.3,
    fontSize: 13, fontFace: "Arial", bold: true, color: COLORS.primary, margin: 0
  });

  slide.addShape(pres.shapes.ROUNDED_RECTANGLE, {
    x: 0.7, y: 3.1, w: 8.6, h: 2.3,
    fill: { color: "EDE6D8" },
    rectRadius: 0.08
  });

  slide.addText([
    { text: "Context: ", options: { bold: true, color: COLORS.primary } },
    { text: "[Paste the relevant public thread, notes, or prior messages]\n\n", options: { color: COLORS.text } },
    { text: "Goal: ", options: { bold: true, color: COLORS.primary } },
    { text: "[What should this help us achieve? e.g. \"Capture decisions and actions so people who missed it can stay in sync\"]\n\n", options: { color: COLORS.text } },
    { text: "Constraints: ", options: { bold: true, color: COLORS.primary } },
    { text: "[Tone, length, audience. e.g. \"Friendly cohort voice. Under 250 words for summaries. Keep names when actions are assigned.\"]\n\n", options: { color: COLORS.text } },
    { text: "Output format: ", options: { bold: true, color: COLORS.primary } },
    { text: "[Bullets / sections / markdown table etc.]\n\n", options: { color: COLORS.text } },
    { text: "Review step: ", options: { bold: true, color: COLORS.primary } },
    { text: "I will read, edit for accuracy and voice, then share. I will note that this is a starting point.", options: { color: COLORS.text } }
  ], {
    x: 0.9, y: 3.2, w: 8.2, h: 2.1,
    fontSize: 11.5, fontFace: "Consolas", margin: 0
  });

  slide.addNotes("PRESENTER NOTES — SLIDE 9\n\n" +
    "This is the 'pattern' slide. Spend a bit more time here (60–90 sec).\n" +
    "Walk the flow left to right: 'Context first, then you tell it the goal and the shape, it gives structured output, you review, then you share something useful.'\n" +
    "Point to the template: 'This is the one I use most often. I copy it into a note and fill in the brackets. The review line at the bottom is important — it reminds me (and anyone who sees it) that a human touched it.'\n" +
    "Transition: 'Diego offered to share how this shows up in WordPress work...'\n");

  // ============================================
  // SLIDE 10: WordPress companion
  // ============================================
  slide = pres.addSlide();
  slide.background = { color: COLORS.bg };

  slide.addText("WordPress companion segment", {
    x: 0.7, y: 0.35, w: 8.6, h: 0.5,
    fontSize: 26, fontFace: "Arial", bold: true,
    color: COLORS.primary, margin: 0
  });

  slide.addShape(pres.shapes.ROUNDED_RECTANGLE, {
    x: 0.7, y: 1.05, w: 8.6, h: 3.6,
    fill: { color: COLORS.card },
    rectRadius: 0.1
  });

  slide.addText("Diego Cusatis offered to share a short companion walkthrough.", {
    x: 1.0, y: 1.25, w: 8.0, h: 0.4,
    fontSize: 16, fontFace: "Arial", bold: true, color: COLORS.text, margin: 0
  });

  slide.addText("How he combines chat + Claude + WordPress for community and content workflows.", {
    x: 1.0, y: 1.7, w: 8.0, h: 0.4,
    fontSize: 15, fontFace: "Arial", color: COLORS.text, margin: 0
  });

  slide.addText("Possible areas (examples he might cover):", {
    x: 1.0, y: 2.25, w: 8.0, h: 0.35,
    fontSize: 13, fontFace: "Arial", bold: true, color: COLORS.secondary, margin: 0
  });

  const wpItems = [
    "Drafting or refining posts and documentation with real site context",
    "Turning support or forum threads into pattern or FAQ improvements",
    "Using chat history + Claude to explore plugin or theme ideas",
    "Lightweight content workflows that stay grounded in actual WordPress work"
  ];

  wpItems.forEach((item, i) => {
    slide.addImage({ data: iconLightbulb, x: 1.0, y: 2.7 + i * 0.42, w: 0.28, h: 0.28 });
    slide.addText(item, {
      x: 1.4, y: 2.68 + i * 0.42, w: 7.5, h: 0.38,
      fontSize: 13, fontFace: "Arial", color: COLORS.text, margin: 0, valign: "middle"
    });
  });

  slide.addText("We'll hand over time during the session for Diego (or a recording of his segment).", {
    x: 1.0, y: 4.35, w: 8.0, h: 0.2,
    fontSize: 12, fontFace: "Arial", italic: true, color: COLORS.muted, margin: 0
  });

  slide.addNotes("PRESENTER NOTES — SLIDE 10\n\n" +
    "This is the handoff / placeholder slide.\n" +
    "Say: 'Diego offered this in the original thread. If he's here today, we'll pass the mic. If not, we have a short recording or he can share later in the thread.'\n" +
    "Do not over-specify what he will show — keep it open and respectful.\n" +
    "Transition after his segment: 'Thanks Diego. Let's talk about doing this responsibly...'\n");

  // ============================================
  // SLIDE 11: Safety and etiquette
  // ============================================
  slide = pres.addSlide();
  slide.background = { color: COLORS.bg };

  slide.addText("Safety and etiquette", {
    x: 0.7, y: 0.35, w: 8.6, h: 0.5,
    fontSize: 26, fontFace: "Arial", bold: true,
    color: COLORS.primary, margin: 0
  });
  slide.addText("Practical rules that keep trust intact in a community setting", {
    x: 0.7, y: 0.85, w: 8.6, h: 0.35,
    fontSize: 14, fontFace: "Arial", italic: true,
    color: COLORS.muted, margin: 0
  });

  const safety = [
    { icon: iconHandshake, title: "Consent first", desc: "Ask before summarizing or quoting a thread that includes other people's words." },
    { icon: iconEye, title: "Prefer public channels", desc: "Be very cautious with DMs and private groups. Default to no." },
    { icon: iconShare, title: "Source clarity", desc: "Link back or name the thread when you share a summary. Give credit." },
    { icon: iconEdit, title: "Review before sharing", desc: "Never paste raw AI output as the voice of the group. Edit first." }
  ];

  safety.forEach((s, i) => {
    const col = i % 2;
    const row = Math.floor(i / 2);
    const x = 0.7 + col * 4.5;
    const y = 1.35 + row * 1.55;

    slide.addShape(pres.shapes.ROUNDED_RECTANGLE, {
      x, y, w: 4.3, h: 1.4,
      fill: { color: COLORS.card },
      rectRadius: 0.08
    });
    slide.addImage({ data: s.icon, x: x + 0.18, y: y + 0.18, w: 0.4, h: 0.4 });
    slide.addText(s.title, {
      x: x + 0.7, y: y + 0.2, w: 3.4, h: 0.35,
      fontSize: 14, fontFace: "Arial", bold: true, color: COLORS.text, margin: 0
    });
    slide.addText(s.desc, {
      x: x + 0.18, y: y + 0.65, w: 3.95, h: 0.65,
      fontSize: 12, fontFace: "Arial", color: COLORS.muted, margin: 0
    });
  });

  slide.addNotes("PRESENTER NOTES — SLIDE 11\n\n" +
    "Timing: 45–60 seconds. This slide is serious but not heavy.\n" +
    "Read the four points briefly.\n" +
    "Add: 'The easiest way to lose trust is to make it look like the AI is speaking for the group or that private conversations are being mined.'\n" +
    "Transition: 'Let's make this real with a short activity.'\n");

  // ============================================
  // SLIDE 12: Activity + next steps + timing
  // ============================================
  slide = pres.addSlide();
  slide.background = { color: COLORS.bg };

  slide.addText("Cohort activity + next steps", {
    x: 0.7, y: 0.3, w: 8.6, h: 0.45,
    fontSize: 24, fontFace: "Arial", bold: true,
    color: COLORS.primary, margin: 0
  });

  // Activity box
  slide.addShape(pres.shapes.ROUNDED_RECTANGLE, {
    x: 0.7, y: 0.85, w: 8.6, h: 1.9,
    fill: { color: COLORS.card },
    rectRadius: 0.08
  });
  slide.addText("5-minute small group / individual practice", {
    x: 0.9, y: 0.95, w: 8.2, h: 0.3,
    fontSize: 14, fontFace: "Arial", bold: true, color: COLORS.primary, margin: 0
  });
  slide.addText("Bring one real or fictional workflow from your community life.\n\nTurn it into one of these using the pattern:\n• A clean summary (decisions / questions / actions)\n• A reusable prompt or facilitation starter\n• A lightweight automation idea that includes a clear human review step\n\nShare one takeaway or example in the thread afterward.", {
    x: 0.9, y: 1.3, w: 8.2, h: 1.35,
    fontSize: 12.5, fontFace: "Arial", color: COLORS.text, margin: 0
  });

  // Next steps
  slide.addText("After today", {
    x: 0.7, y: 2.9, w: 8.6, h: 0.3,
    fontSize: 14, fontFace: "Arial", bold: true, color: COLORS.primary, margin: 0
  });

  const nexts = [
    "Try the pattern on one small thing this week",
    "Share a prompt or summary that worked (or didn't) in the thread",
    "If interested, follow up with Diego on the WP side",
    "We'll keep iterating together — this is not a finished product"
  ];

  nexts.forEach((n, i) => {
    const y = 3.25 + i * 0.38;
    slide.addImage({ data: iconCheck, x: 0.7, y: y + 0.03, w: 0.24, h: 0.24 });
    slide.addText(n, {
      x: 1.05, y, w: 8.0, h: 0.35,
      fontSize: 12.5, fontFace: "Arial", color: COLORS.text, margin: 0, valign: "middle"
    });
  });

  // Timing reminder at bottom
  slide.addShape(pres.shapes.ROUNDED_RECTANGLE, {
    x: 0.7, y: 4.85, w: 8.6, h: 0.6,
    fill: { color: COLORS.primary },
    rectRadius: 0.06
  });
  slide.addText("Timing today: 2 min intro • 5 min demos • 5 min practice • 3 min discussion", {
    x: 0.9, y: 4.95, w: 8.2, h: 0.4,
    fontSize: 13, fontFace: "Arial", bold: true, color: COLORS.white, margin: 0, align: "center", valign: "middle"
  });

  slide.addNotes("PRESENTER NOTES — SLIDE 12\n\n" +
    "Timing: 5 min practice + 3 min discussion.\n" +
    "Instructions: 'Take 5 minutes now. Use the template or just the spirit of it. You can work alone or in pairs. Pick something real or make one up.'\n" +
    "After: Quick round of shares — 2-3 people max. 'What workflow did you pick? What shape did your output take?'\n" +
    "Close: Thank people. Remind them the thread is the place to continue. Mention Diego's segment if not yet done.\n" +
    "End on: 'The point isn't perfect automation. It's making the conversations we already have a little more useful to more people, with a human still in charge.'\n");

  // Write the file
  await pres.writeFile({ fileName: "/home/dev/flavor-agent/deck/Claude-Slack-Community-Workflows.pptx" });
  console.log("Presentation created successfully!");
}

createPresentation().catch(console.error);