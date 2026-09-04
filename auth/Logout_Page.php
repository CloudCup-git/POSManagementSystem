<?php
// ── LOGOUT ────────────────────────────────────────────────────────
session_start();

// Clear all session variables
$_SESSION = [];

// Destroy the session cookie itself (not just the data) — without this
// the browser keeps sending the old session ID after logout
if (isset($_COOKIE[session_name()])) {
    setcookie(session_name(), '', time() - 3600, '/');
}

// Destroy the session on the server
session_destroy();

// Prevent this response (and the page the user is coming from) from
// being served out of the browser's cache via Back/Forward
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<title>Signing out…</title>
<style>
  @import url('https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600&family=Work+Sans:wght@400;500&display=swap');

  :root{
    --espresso-950:#241609;
    --espresso-900:#3c2317;
    --espresso-800:#4f3021;
    --crema:#f5efe6;
    --crema-dim:#b4cde6;
    --clay:#628e90;
    --clay-bright:#7fa6a8;
    --liquid-dark:#2c1a10;
    --liquid-light:#4f3021;
  }

  *{box-sizing:border-box;}

  html,body{
    height:100%;
    margin:0;
  }

  body{
    position:relative;
    display:flex;
    align-items:center;
    justify-content:center;
    min-height:100vh;
    background:
      radial-gradient(ellipse 900px 600px at 50% 20%, rgba(98,142,144,0.12), transparent 60%),
      var(--espresso-950);
    font-family:'Work Sans', sans-serif;
    color:var(--crema);
    overflow:hidden;
    opacity:0;
    animation: bodyIn 0.5s ease-out forwards;
  }
  @keyframes bodyIn{ to{ opacity:1; } }

  /* slow ambient breathing behind the card, echoes the login page's glow */
  body::after{
    content:'';
    position:absolute;
    inset:0;
    background:radial-gradient(ellipse 900px 600px at 50% 20%, rgba(98,142,144,0.14), transparent 60%);
    opacity:.5;
    pointer-events:none;
    animation: glowBreathe 6s ease-in-out infinite;
  }
  @keyframes glowBreathe{
    0%,100%{ opacity:.35; }
    50%{ opacity:.85; }
  }

  /* faint texture on the backdrop, purely atmospheric */
  body::before{
    content:'';
    position:absolute;
    inset:0;
    background-image:radial-gradient(circle, rgba(245,239,230,0.035) 1px, transparent 1px);
    background-size:26px 26px;
    pointer-events:none;
  }

  .card{
    position:relative;
    width:380px;
    max-width:90vw;
    padding:52px 40px 40px;
    background:linear-gradient(180deg, var(--espresso-900), var(--espresso-800));
    border-radius:6px;
    border:1px solid rgba(242,226,200,0.08);
    box-shadow:
      0 40px 80px -30px rgba(0,0,0,0.6),
      0 0 0 1px rgba(0,0,0,0.2);
    text-align:center;
    opacity:0;
    animation:
      cardIn 0.6s ease-out forwards,
      cardOut 0.5s ease-in 2.6s forwards;
  }
  @keyframes cardIn{
    0%   { opacity:0; transform:translateY(6px) scale(1); }
    100% { opacity:1; transform:translateY(0) scale(1); }
  }
  @keyframes cardOut{
    0%   { opacity:1; transform:translateY(0) scale(1); }
    100% { opacity:0; transform:translateY(-4px) scale(0.98); }
  }

  /* thin poured-line accent along the top edge of the card */
  .card::before{
    content:'';
    position:absolute;
    top:0; left:10%; right:10%;
    height:2px;
    background:linear-gradient(90deg, transparent, var(--clay-bright), transparent);
    opacity:0.7;
  }

  .stage{
    position:relative;
    width:180px;
    height:150px;
    margin:0 auto 28px;
  }

  /* ---------- steam: rises, then fades for good as the cup empties ---------- */
  .steam{
    position:absolute;
    top:-46px;
    left:0;
    width:100%;
    height:56px;
    animation: steamLife 1.6s ease-in-out forwards;
  }
  .steam path{
    fill:none;
    stroke:var(--crema-dim);
    stroke-width:2.5;
    stroke-linecap:round;
    opacity:0;
  }
  .steam .s1{ animation: rise 1.6s ease-in forwards; }
  .steam .s2{ animation: rise 1.6s ease-in 0.3s forwards; }
  .steam .s3{ animation: rise 1.6s ease-in 0.6s forwards; }

  @keyframes rise{
    0%   { opacity:0; transform:translateY(6px) scaleY(0.8); }
    22%  { opacity:0.55; }
    80%  { opacity:0.12; }
    100% { opacity:0; transform:translateY(-30px) scaleY(1.15); }
  }

  @keyframes steamLife{
    0%   { opacity:1; }
    75%  { opacity:1; }
    100% { opacity:0; }
  }

  /* ---------- mug ---------- */
  .mug-wrap{
    position:absolute;
    bottom:14px;
    left:50%;
    transform:translateX(-50%);
    width:150px;
    height:104px;
  }

  .liquid-clip-rect{
    animation: drain 1.6s cubic-bezier(.65,0,.35,1) forwards;
  }

  @keyframes drain{
    0%   { y:14; height:58; }
    38%  { y:14; height:58; }
    95%  { y:70; height:2; }
    100% { y:70; height:2; }
  }

  /* subtle liquid-surface wobble while it's still full, then it drains away */
  .liquid-surface{
    animation:
      wobble 1.3s ease-in-out 2 forwards,
      drainSurface 1.6s cubic-bezier(.65,0,.35,1) forwards;
    transform-origin:center;
  }
  @keyframes wobble{
    0%,100%{ transform:scaleX(1) translateY(0); }
    50%    { transform:scaleX(0.97) translateY(0.5px); }
  }
  @keyframes drainSurface{
    0%   { opacity:1; }
    38%  { opacity:1; }
    85%  { opacity:0; }
    100% { opacity:0; }
  }

  /* falling drip into the saucer, timed with the drain finishing */
  .drip{
    opacity:0;
    animation: dripFall 0.6s cubic-bezier(.55,0,.85,.35) 1.3s forwards;
  }
  @keyframes dripFall{
    0%    { opacity:1; transform:translateY(0); }
    75%   { opacity:1; transform:translateY(26px); }
    100%  { opacity:0; transform:translateY(28px); }
  }

  .ripple{
    opacity:0;
    transform-origin:center;
    animation: rippleGrow 0.6s ease-out 1.75s forwards;
  }
  @keyframes rippleGrow{
    0%    { opacity:0;   transform:scale(0.3); }
    15%   { opacity:0.6; transform:scale(0.5); }
    100%  { opacity:0;   transform:scale(1.3); }
  }

  h1{
    font-family:'Fraunces', serif;
    font-weight:500;
    font-size:26px;
    letter-spacing:0.2px;
    margin:0 0 10px;
    color:var(--crema);
  }

  .sub{
    position:relative;
    margin:0;
    height:20px;
    font-size:14px;
    line-height:20px;
  }
  .sub .msg{
    position:absolute;
    top:0;
    left:0; right:0;
    color:var(--crema-dim);
    white-space:nowrap;
  }
  .msg-progress{
    opacity:1;
    animation: fadeOut 0.35s ease-in 1.85s forwards;
  }
  .msg-done{
    opacity:0;
    animation: fadeIn 0.4s ease-out 2.0s forwards;
  }
  @keyframes fadeOut{
    to{ opacity:0; }
  }
  @keyframes fadeIn{
    to{ opacity:1; }
  }

  .status-icon{
    position:relative;
    display:inline-block;
    width:16px;
    height:16px;
    margin-left:6px;
    vertical-align:-3px;
  }

  .dots{
    position:absolute;
    inset:0;
    display:inline-flex;
    align-items:center;
    gap:5px;
    animation: fadeOut 0.3s ease-in 1.85s forwards;
  }
  .dots span{
    width:4px; height:4px;
    border-radius:50%;
    background:var(--clay-bright);
    display:inline-block;
    animation: dotPulse 1.4s ease-in-out 1.85s infinite;
  }
  .dots span:nth-child(2){ animation-delay:2.03s; }
  .dots span:nth-child(3){ animation-delay:2.21s; }
  @keyframes dotPulse{
    0%,80%,100%{ opacity:0.25; transform:translateY(0); }
    40%{ opacity:1; transform:translateY(-2px); }
  }

  /* completion checkmark, drawn on once the cup is empty */
  .check{
    position:absolute;
    inset:0;
    opacity:0;
    transform:scale(0.6);
    animation: checkIn 0.4s cubic-bezier(.34,1.56,.64,1) 2.0s forwards;
  }
  @keyframes checkIn{
    to{ opacity:1; transform:scale(1); }
  }
  .check circle{
    fill:none;
    stroke:var(--clay-bright);
    stroke-width:1.6;
    stroke-dasharray:44;
    stroke-dashoffset:44;
    animation: drawRing 0.45s ease-out 2.0s forwards;
  }
  .check path{
    fill:none;
    stroke:var(--clay-bright);
    stroke-width:2;
    stroke-linecap:round;
    stroke-linejoin:round;
    stroke-dasharray:12;
    stroke-dashoffset:12;
    animation: drawTick 0.3s ease-out 2.25s forwards;
  }
  @keyframes drawRing{ to{ stroke-dashoffset:0; } }
  @keyframes drawTick{ to{ stroke-dashoffset:0; } }

  @media (prefers-reduced-motion: reduce){
    *{ animation:none !important; }
    body{ opacity:1; }
    .card{ opacity:1; }
    .steam{ opacity:0; }
    .liquid-clip-rect{ height:2px; y:70; }
    .msg-progress{ opacity:0; }
    .msg-done{ opacity:1; }
    .dots{ opacity:0; }
    .check{ opacity:1; transform:scale(1); }
    .check circle, .check path{ stroke-dashoffset:0; }
  }

  noscript p{
    font-family:'Work Sans', sans-serif;
    color:var(--crema);
    text-align:center;
  }
  noscript a{ color:var(--clay-bright); }
</style>
</head>
<body>

  <div class="card">
    <div class="stage">

      <svg class="steam" viewBox="0 0 150 56">
        <path class="s1" d="M55 50 C 48 40, 62 34, 55 24 C 49 15, 60 10, 56 2" />
        <path class="s2" d="M75 50 C 68 39, 83 33, 75 22 C 69 13, 81 8, 76 0" />
        <path class="s3" d="M95 50 C 88 40, 102 34, 95 24 C 89 15, 100 10, 96 2" />
      </svg>

      <svg class="mug-wrap" viewBox="0 0 150 104">
        <!-- saucer -->
        <ellipse cx="75" cy="96" rx="62" ry="6" fill="#140d08"/>
        <ellipse cx="75" cy="94" rx="58" ry="5.5" fill="#241609"/>

        <!-- ripple in saucer, appears once the mug has drained -->
        <ellipse class="ripple" cx="86" cy="94" rx="10" ry="3" fill="none" stroke="#628e90" stroke-width="1.4"/>

        <!-- drip falling from mug lip to saucer -->
        <circle class="drip" cx="86" cy="60" r="2.6" fill="var(--liquid-dark)"/>

        <!-- handle -->
        <path d="M124 30 C 148 30, 148 62, 124 62" fill="none" stroke="#e3d9c8" stroke-width="8" stroke-linecap="round"/>
        <path d="M124 30 C 148 30, 148 62, 124 62" fill="none" stroke="#cbb89e" stroke-width="8" stroke-linecap="round" opacity="0.35" stroke-dasharray="1 200"/>

        <!-- mug body -->
        <path d="M20 12 L 128 12 L 121 74 C 121 84, 108 90, 74 90 C 40 90, 27 84, 27 74 Z"
              fill="#f5efe6" stroke="#b4cde6" stroke-width="1.5"/>

        <!-- liquid, clipped to the mug's inner shape -->
        <clipPath id="mugInner">
          <path d="M23.5 15 L 124.5 15 L 118 73 C 118 81.5, 106 87, 74 87 C 42 87, 30 81.5, 30 73 Z"/>
        </clipPath>

        <g clip-path="url(#mugInner)">
          <rect class="liquid-clip-rect" x="20" y="14" width="110" height="58" fill="var(--liquid-dark)"/>
          <ellipse class="liquid-surface" cx="75" cy="14" rx="55" ry="4.5" fill="var(--liquid-light)"/>
        </g>

        <!-- rim highlight -->
        <path d="M20 12 L 128 12" stroke="#fffaf1" stroke-width="2" stroke-linecap="round" opacity="0.7"/>
      </svg>

    </div>

    <h1>Signing out</h1>
    <p class="sub">
      <span class="msg msg-progress">Closing your session
        <span class="status-icon">
          <span class="dots"><span></span><span></span><span></span></span>
        </span>
      </span>
      <span class="msg msg-done">Signed out safely
        <span class="status-icon">
          <svg class="check" viewBox="0 0 16 16" width="16" height="16">
            <circle cx="8" cy="8" r="7" />
            <path d="M4.5 8.3 L7 10.8 L11.5 5.8" />
          </svg>
        </span>
      </span>
    </p>
  </div>

  <script>
    // Session is already destroyed server-side at this point; this is
    // just a brief, reassuring confirmation before we send the user
    // back to the login page.
    var target = '../auth/Login_Page.php';

    function goToTarget() {
      window.location.replace(target);
    }

    // Let the pour, the checkmark, and the card's own fade-out finish
    // before navigating, so the handoff feels like one continuous motion.
    var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    setTimeout(goToTarget, reduceMotion ? 700 : 3100);
  </script>
  <noscript>
    <meta http-equiv="refresh" content="0;url=../auth/Login_Page.php">
    <p>Logging out… <a href="../auth/Login_Page.php">Continue</a></p>
  </noscript>
</body>
</html>
<?php
exit;
