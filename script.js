"use strict";

/**
 * v1.6 — Integrated Admin Dashboard (Metrics + Users) + Pre-start Metrics Logging
 * Keeps the core assessment flow: email gate -> countdown -> unskippable video.
 */

const API_AUTH = "api_auth.php";
const API_METRICS = "api_metrics.php";
const API_ADMIN = "api_admin.php";

// ------- DOM -------
const startBtn = document.getElementById("startBtn");
const emailInput = document.getElementById("email");
const errorMessage = document.getElementById("error-message");
const countdownSection = document.getElementById("countdown-section");
const countdownSpan = document.getElementById("countdown");
const videoSection = document.getElementById("video-section");
const assessmentAudio = document.getElementById("assessmentAudio");
const progressFill = document.getElementById("progressFill");
const timeCurrent = document.getElementById("timeCurrent");
const timeTotal = document.getElementById("timeTotal");
const assessmentVisualizer = document.getElementById("assessmentVisualizer");
const assessmentBars = assessmentVisualizer.querySelectorAll(".bar");
const testAudioBtn = document.getElementById("testAudioBtn");
const testAudio = document.getElementById("testAudio");
const cancelCountdownBtn = document.getElementById("cancelCountdownBtn");
const audioVisualizer = document.getElementById("audioVisualizer");
const bars = audioVisualizer.querySelectorAll(".bar");

// Admin DOM
const adminOpenBtn = document.getElementById("adminOpenBtn");
const adminDashboard = document.getElementById("adminDashboard");
const adminRefreshBtn = document.getElementById("adminRefreshBtn");
const adminLogoutBtn = document.getElementById("adminLogoutBtn");
const tabButtons = document.querySelectorAll(".tabbtn");
const metricsTab = document.getElementById("metricsTab");
const usersTab = document.getElementById("usersTab");
const metricsBody = document.getElementById("metricsBody");
const usersBody = document.getElementById("usersBody");

const adminOverlay = document.getElementById("adminOverlay");
const adminModal = document.getElementById("adminModal");
const adminCloseBtn = document.getElementById("adminCloseBtn");
const adminLoginBtn = document.getElementById("adminLoginBtn");
const adminEmailInput = document.getElementById("adminEmail");
const adminPasswordInput = document.getElementById("adminPassword");
const adminLoginError = document.getElementById("adminLoginError");

const addUserForm = document.getElementById("addUserForm");
const newUserEmail = document.getElementById("newUserEmail");
const newUserRole = document.getElementById("newUserRole");
const newUserPasswordWrap = document.getElementById("newUserPasswordWrap");
const newUserPassword = document.getElementById("newUserPassword");

let countdownInterval = null;

// ------- Helpers -------
async function postJSON(url, payload) {
  const res = await fetch(url, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    credentials: "same-origin",
    body: JSON.stringify(payload),
  });

  // handle non-JSON errors cleanly
  const text = await res.text();
  try {
    const data = JSON.parse(text);
    return { ok: res.ok, status: res.status, data };
  } catch (e) {
    return {
      ok: false,
      status: res.status,
      data: { error: "Non-JSON response", raw: text },
    };
  }
}

function toLowerEmail(value) {
  return (value || "").trim().toLowerCase();
}

function fmtTs(ts) {
  if (!ts) return "";
  // Expect server returns "YYYY-MM-DD HH:MM:SS" or ISO; Date can parse ISO more reliably.
  const d = new Date(ts.replace(" ", "T") + (ts.includes("Z") ? "" : ""));
  if (Number.isNaN(d.getTime())) return ts;
  return d.toLocaleString();
}

function seconds(ms) {
  if (!ms && ms !== 0) return "";
  return Math.round(ms / 1000);
}

function badgeYesNo(val) {
  const yes =
    !!val && (val === true || val === 1 || val === "1" || val === "yes");
  return `<span class="badge ${yes ? "badge--ok" : "badge--no"}">${yes ? "Yes" : "No"}</span>`;
}

function fmtTime(sec) {
  if (!Number.isFinite(sec) || sec < 0) return "--:--";
  const s = Math.floor(sec);
  const m = Math.floor(s / 60);
  const r = String(s % 60).padStart(2, "0");
  return `${m}:${r}`;
}

function startAssessmentBars() {
  assessmentBars.forEach((bar) => bar.classList.add("playing"));
}

function stopAssessmentBars() {
  assessmentBars.forEach((bar) => {
    bar.classList.remove("playing");
    bar.style.height = "6px";
  });
}

// ------- Countdown beep (soft audio cue) -------
let beepCtx = null;

function getBeepCtx() {
  if (!beepCtx) {
    const Ctx = window.AudioContext || window.webkitAudioContext;
    beepCtx = new Ctx();
  }
  return beepCtx;
}

async function beepOnce(freq = 740, durationSec = 0.06, volume = 0.03) {
  try {
    const ctx = getBeepCtx();
    if (ctx.state === "suspended") await ctx.resume();

    const osc = ctx.createOscillator();
    const gain = ctx.createGain();

    osc.type = "triangle";
    osc.frequency.value = freq;

    osc.connect(gain);
    gain.connect(ctx.destination);

    const t = ctx.currentTime;

    // quick fade in/out to avoid clicks
    gain.gain.setValueAtTime(0.0001, t);
    gain.gain.linearRampToValueAtTime(volume, t + 0.005);
    gain.gain.exponentialRampToValueAtTime(0.0001, t + durationSec);

    osc.start(t);
    osc.stop(t + durationSec + 0.02);
  } catch {
    // ignore (some environments block audio context)
  }
}

function primeBeepAudio() {
  try {
    const ctx = getBeepCtx();

    // Kick the context into "running" state on a real user gesture
    if (ctx.state === "suspended") {
      ctx.resume().catch(() => {});
    }

    // Some setups need an actual node to start/stop once to fully unlock audio output
    const osc = ctx.createOscillator();
    const gain = ctx.createGain();
    gain.gain.value = 0; // silent
    osc.connect(gain);
    gain.connect(ctx.destination);
    osc.start();
    osc.stop(ctx.currentTime + 0.01);
  } catch {
    // ignore
  }
}

// ------- Email gate + metrics state -------
let username = "";
let sessionId = null;

const pageLoadMs = Date.now();
let firstFocusMs = null;

let focusedMs = 0;
let focusStartMs = null;
let tabHiddenCount = 0;

let audioTestedBeforeStart = false;
let audioTestCount = 0;
let copyCountBeforeStart = 0;

let startClickedMs = null;
let countdownCancelled = false;

// Unique client session id
function makeClientSessionId() {
  if (window.crypto && crypto.randomUUID) return crypto.randomUUID();
  return (
    "cs_" + Math.random().toString(16).slice(2) + "_" + Date.now().toString(16)
  );
}
const clientSessionId = makeClientSessionId();

function pageIsActive() {
  return document.visibilityState === "visible" && document.hasFocus();
}

function updateFocusTracking() {
  const active = pageIsActive();

  if (active && firstFocusMs === null) firstFocusMs = Date.now() - pageLoadMs;

  // only track pre-start focus time
  if (startClickedMs !== null) {
    // once started, stop tracking for pre-start
    if (focusStartMs !== null) {
      focusedMs += Date.now() - focusStartMs;
      focusStartMs = null;
    }
    return;
  }

  if (active && focusStartMs === null) {
    focusStartMs = Date.now();
  } else if (!active && focusStartMs !== null) {
    focusedMs += Date.now() - focusStartMs;
    focusStartMs = null;
  }
}

window.addEventListener("focus", updateFocusTracking);
window.addEventListener("blur", updateFocusTracking);
document.addEventListener("visibilitychange", () => {
  if (document.visibilityState === "hidden" && startClickedMs === null)
    tabHiddenCount += 1;
  updateFocusTracking();
});

// Track copy attempts (pre-start)
document.addEventListener("copy", () => {
  if (startClickedMs === null) copyCountBeforeStart += 1;
});

// Disable context menu + some devtools shortcuts (best-effort, not security)

// ------- Audio visualizer -------
function startBars() {
  bars.forEach((bar) => {
    bar.classList.add("playing");
    bar.style.height = "";
  });
}
function stopBars() {
  bars.forEach((bar) => {
    bar.classList.remove("playing");
    bar.style.animation = "none";
    bar.offsetHeight;
    bar.style.animation = "";
    bar.style.height = "5px";
  });
}

testAudioBtn.addEventListener("click", () => {
  if (testAudio.paused) {
    testAudio.play();
    testAudioBtn.textContent = "Stop Audio";
    startBars();

    // Track audio test pre-start
    if (startClickedMs === null) {
      audioTestedBeforeStart = true;
      audioTestCount += 1;
    }
  } else {
    testAudio.pause();
    testAudio.currentTime = 0;
    testAudioBtn.textContent = "Test Audio";
    stopBars();
  }
});
testAudio.addEventListener("ended", () => {
  testAudioBtn.textContent = "Test Audio";
  stopBars();
});

// ------- Email "Started" notification (keeps your original behavior) -------
function sendFeedbackStartedEmail() {
  const data = { feedback: emailInput.value };
  const xhr = new XMLHttpRequest();
  xhr.open("POST", "feedback.php", true);
  xhr.setRequestHeader("Content-Type", "application/json");
  xhr.send(JSON.stringify(data));
}

// ------- Video play lockdown -------
function playAssessmentAudio() {
  videoSection.classList.remove("hidden");

  // hard-disable controls
  assessmentAudio.controls = false;
  assessmentAudio.currentTime = 0;

  // Start playback
  assessmentAudio.play().catch(() => {
    // If autoplay is blocked for any reason, show message (rare after user click)
    const lbl = document.getElementById("playerLabel");
    if (lbl) lbl.textContent = "Click Start again to begin audio…";
  });

  startAssessmentBars();

  // Prevent pausing / seeking (best-effort; not “security”)
  assessmentAudio.addEventListener("pause", () => {
    if (!assessmentAudio.ended) assessmentAudio.play();
  });

  assessmentAudio.addEventListener("seeking", () => {
    // snap back if user tries to seek
    if (assessmentAudio.currentTime > 0.01) assessmentAudio.currentTime = 0;
  });

  // Progress + timecode updates
  const updateProgress = () => {
    const dur = assessmentAudio.duration;
    const cur = assessmentAudio.currentTime;

    if (Number.isFinite(dur) && dur > 0) {
      const pct = Math.max(0, Math.min(1, cur / dur)) * 100;
      progressFill.style.width = pct.toFixed(2) + "%";
      timeCurrent.textContent = fmtTime(cur);
      timeTotal.textContent = fmtTime(dur);
    } else {
      // duration not yet known
      timeCurrent.textContent = fmtTime(cur);
      timeTotal.textContent = "--:--";
    }
  };

  assessmentAudio.addEventListener("timeupdate", updateProgress);
  assessmentAudio.addEventListener("loadedmetadata", updateProgress);
  assessmentAudio.addEventListener("durationchange", updateProgress);

  assessmentAudio.addEventListener("ended", async () => {
    stopAssessmentBars();
    progressFill.style.width = "100%";
    alert("Assessment audio has ended.");
    if (sessionId) {
      await postJSON(API_METRICS, {
        action: "video_ended",
        session_id: sessionId,
      });
    }
  });
}

// ------- Core Assessment Flow -------
async function ensureSessionAndGate(email) {
  const gate = await postJSON(API_AUTH, { action: "gate", email });

  if (!gate.ok || !gate.data || !gate.data.ok) {
    const msg =
      (gate.data && (gate.data.message || gate.data.error)) ||
      "Email not recognized.";
    return { ok: false, message: msg };
  }

  // Create a session row (one per page load + user)
  const startPayload = {
    action: "session_start",
    client_session_id: clientSessionId,
    user_agent: navigator.userAgent,
    first_focus_ms: firstFocusMs,
  };

  const s = await postJSON(API_METRICS, startPayload);
  if (!s.ok || !s.data || !s.data.ok) {
    const msg =
      (s.data && (s.data.message || s.data.error)) ||
      "Failed to start session logging.";
    return { ok: false, message: msg };
  }

  sessionId = s.data.session_id;
  return { ok: true, role: gate.data.role };
}

function computePrestartMetrics() {
  // finalize pre-start focus time
  const focusMsNow = focusedMs + (focusStartMs ? Date.now() - focusStartMs : 0);
  const wallMsNow = Date.now() - pageLoadMs;

  return {
    focus_ms_before_start: Math.max(0, Math.round(focusMsNow)),
    wall_ms_before_start: Math.max(0, Math.round(wallMsNow)),
    audio_tested_before_start: audioTestedBeforeStart ? 1 : 0,
    audio_test_count: audioTestCount,
    copy_count_before_start: copyCountBeforeStart,
    tab_hidden_count_before_start: tabHiddenCount,
  };
}

async function updatePrestartOnServer(extra = {}) {
  if (!sessionId) return;
  const m = computePrestartMetrics();

  await postJSON(API_METRICS, {
    action: "prestart_update",
    session_id: sessionId,
    ...m,
    ...extra,
  });
}

startBtn.addEventListener("click", async () => {
  const email = toLowerEmail(emailInput.value);
  username = email;

  if (!email.endsWith("@3playmedia.com")) {
    errorMessage.textContent = "Please enter your @3playmedia.com email.";
    return;
  }

  startBtn.disabled = true;
  errorMessage.textContent = "";

  primeBeepAudio();

  updateFocusTracking();

  const gateRes = await ensureSessionAndGate(email);

  if (!gateRes.ok) {
    startBtn.disabled = false;
    errorMessage.textContent = gateRes.message;
    return;
  }

  // Record the moment they hit Start (admin dashboard shows this)
  startClickedMs = Date.now();
  await updatePrestartOnServer({
    start_clicked_client_ms: startClickedMs,
    countdown_cancelled: 0,
  });

  // UI transitions
  document.getElementById("form-section").classList.add("hidden");
  countdownSection.classList.remove("hidden");

  testAudio.pause();

  let countdown = 10;
  countdownSpan.textContent = countdown;

  // beep immediately on "10"
  beepOnce(740, 0.08, 0.08);

  countdownInterval = setInterval(async () => {
    countdown -= 1;
    countdownSpan.textContent = countdown;

    if (countdown > 0) beepOnce(740, 0.08, 0.08);
    else beepOnce(880, 0.08, 0.03);

    if (countdown <= 0) {
      clearInterval(countdownInterval);
      countdownSection.classList.add("hidden");
      playAssessmentAudio();
      await postJSON(API_METRICS, {
        action: "video_started",
        session_id: sessionId,
      });
      sendFeedbackStartedEmail();
    }
  }, 1000);
});

cancelCountdownBtn.addEventListener("click", async () => {
  clearInterval(countdownInterval);
  countdownSection.classList.add("hidden");
  document.getElementById("form-section").classList.remove("hidden");

  countdownCancelled = true;
  startBtn.disabled = false;

  await updatePrestartOnServer({ countdown_cancelled: 1 });
});

// ------- Admin UI -------
function openAdminModal() {
  adminLoginError.textContent = "";
  adminOverlay.classList.remove("hidden");
  adminModal.classList.remove("hidden");
  adminModal.setAttribute("aria-hidden", "false");
  document.body.classList.add("modal-open");
  adminEmailInput.value = "";
  adminPasswordInput.value = "";
  window.requestAnimationFrame(() => adminEmailInput.focus());
}

function closeAdminModal({ restoreFocus = true } = {}) {
  adminOverlay.classList.add("hidden");
  adminModal.classList.add("hidden");
  adminOverlay.setAttribute("aria-hidden", "true");
  adminModal.setAttribute("aria-hidden", "true");
  document.body.classList.remove("modal-open");
  if (restoreFocus) adminOpenBtn.focus();
}

adminOpenBtn.addEventListener("click", openAdminModal);
adminCloseBtn.addEventListener("click", closeAdminModal);
adminOverlay.addEventListener("click", closeAdminModal);

[adminEmailInput, adminPasswordInput].forEach((input) => {
  input.addEventListener("keydown", (e) => {
    if (e.key === "Enter") {
      e.preventDefault();
      adminLogin();
    }
  });
});

document.addEventListener("keydown", (e) => {
  if (e.key === "Escape" && !adminModal.classList.contains("hidden")) {
    closeAdminModal();
  }
});

async function adminWhoAmI() {
  const r = await postJSON(API_AUTH, { action: "whoami" });
  if (r.ok && r.data && r.data.ok && r.data.role === "admin") return true;
  return false;
}

async function adminLogin() {
  if (adminLoginBtn.disabled) return false;

  adminLoginBtn.disabled = true;
  adminLoginError.textContent = "";

  const email = toLowerEmail(adminEmailInput.value);
  const password = adminPasswordInput.value || "";

  const r = await postJSON(API_AUTH, {
    action: "admin_login",
    email,
    password,
  });

  adminLoginBtn.disabled = false;

  if (!r.ok || !r.data || !r.data.ok) {
    adminLoginError.textContent =
      (r.data && (r.data.message || r.data.error)) || "Login failed.";
    return false;
  }

  closeAdminModal({ restoreFocus: false });
  showAdminDashboard();
  return true;
}

adminLoginBtn.addEventListener("click", adminLogin);

adminLogoutBtn.addEventListener("click", async () => {
  await postJSON(API_AUTH, { action: "logout" });
  adminDashboard.classList.add("hidden");
});

function showAdminDashboard() {
  adminDashboard.classList.remove("hidden");
  loadMetrics();
  loadUsers();
  adminDashboard.scrollIntoView({ behavior: "smooth", block: "start" });
}

tabButtons.forEach((btn) => {
  btn.addEventListener("click", () => {
    tabButtons.forEach((b) => b.classList.remove("active"));
    btn.classList.add("active");

    const tab = btn.getAttribute("data-tab");
    if (tab === "metricsTab") {
      metricsTab.classList.remove("hidden");
      usersTab.classList.add("hidden");
      loadMetrics();
    } else {
      usersTab.classList.remove("hidden");
      metricsTab.classList.add("hidden");
      loadUsers();
    }
  });
});

adminRefreshBtn.addEventListener("click", () => {
  // refresh whichever tab is visible
  if (!metricsTab.classList.contains("hidden")) loadMetrics();
  if (!usersTab.classList.contains("hidden")) loadUsers();
});

// Users: show/hide password field based on role
newUserRole.addEventListener("change", () => {
  const role = newUserRole.value;
  if (role === "admin") newUserPasswordWrap.classList.remove("hidden");
  else newUserPasswordWrap.classList.add("hidden");
});

async function loadMetrics() {
  // 10 columns (includes Status)
  metricsBody.innerHTML = `<tr><td colspan="10" class="subtle">Loading…</td></tr>`;

  const r = await postJSON(API_ADMIN, {
    action: "metrics_users_rollup",
    limit: 2000,
  });

  if (!r.ok || !r.data) {
    metricsBody.innerHTML = `<tr><td colspan="10" style="color:#fecaca">Failed to load metrics (network).</td></tr>`;
    return;
  }

  if (!r.data.ok) {
    const msg = r.data.message || r.data.error || "Failed to load metrics.";
    metricsBody.innerHTML = `<tr><td colspan="10" style="color:#fecaca">${msg}</td></tr>`;
    return;
  }

  const rows = r.data.rows || [];
  if (!rows.length) {
    metricsBody.innerHTML = `<tr><td colspan="10" class="subtle">No users found.</td></tr>`;
    return;
  }

  metricsBody.innerHTML = rows
    .map((s) => {
      const hasSession = !!s.created_at;
      const focusSec = hasSession
        ? seconds(Number(s.focus_ms_before_start || 0))
        : "";
      const wallSec = hasSession
        ? seconds(Number(s.wall_ms_before_start || 0))
        : "";

      let status = "Not started";
      if (hasSession) status = "Loaded";
      if (s.start_clicked_at) status = "Started";
      if (s.video_started_at) status = "In progress";
      if (s.video_ended_at) status = "Finished";
      if (Number(s.countdown_cancelled) === 1) status = "Cancelled";

      const statusBadge = `<span class="badge">${status}</span>`;

      return `
        <tr>
          <td>${s.email || ""}</td>
          <td>${statusBadge}</td>
          <td>${fmtTs(s.created_at)}</td>
          <td>${fmtTs(s.start_clicked_at)}</td>
          <td>${badgeYesNo(s.audio_tested_before_start)}</td>
          <td>${s.copy_count_before_start || 0}</td>
          <td>${focusSec}</td>
          <td>${wallSec}</td>
          <td>${s.tab_hidden_count_before_start || 0}</td>
          <td>${badgeYesNo(s.video_ended_at)}</td>
        </tr>
      `;
    })
    .join("");
}

async function loadUsers() {
  usersBody.innerHTML = `<tr><td colspan="5" class="subtle">Loading…</td></tr>`;

  const r = await postJSON(API_ADMIN, { action: "users_list", limit: 500 });

  if (!r.ok || !r.data || !r.data.ok) {
    usersBody.innerHTML = `<tr><td colspan="5" style="color:#fecaca">Failed to load users.</td></tr>`;
    return;
  }

  const users = r.data.users || [];
  if (!users.length) {
    usersBody.innerHTML = `<tr><td colspan="5" class="subtle">No users found.</td></tr>`;
    return;
  }

  usersBody.innerHTML = users
    .map((u) => {
      const active = Number(u.is_active) === 1;
      const statusBadge = `<span class="badge ${active ? "badge--ok" : "badge--no"}">${active ? "Active" : "Inactive"}</span>`;
      const actionLabel = active ? "Delete" : "Restore";
      const actionClass = active ? "btn--danger" : "btn--ghost";
      const action = active ? "users_deactivate" : "users_reactivate";

      return `
        <tr>
          <td>${u.email}</td>
          <td><span class="badge">${u.role}</span></td>
          <td>${statusBadge}</td>
          <td>${fmtTs(u.created_at)}</td>
          <td>
            <button class="${actionClass} small" type="button"
              data-action="${action}" data-userid="${u.id}" data-email="${u.email}">
              ${actionLabel}
            </button>
          </td>
        </tr>
      `;
    })
    .join("");
}

// User actions (delegated)
usersBody.addEventListener("click", async (e) => {
  const btn = e.target.closest("button[data-action]");
  if (!btn) return;

  const action = btn.getAttribute("data-action");
  const userId = btn.getAttribute("data-userid");
  const email = btn.getAttribute("data-email");

  if (action === "users_deactivate") {
    const ok = confirm(
      `Deactivate (delete) access for:\n\n${email}\n\nHistorical metrics will remain.`,
    );
    if (!ok) return;
  }

  btn.disabled = true;
  const r = await postJSON(API_ADMIN, { action, user_id: userId });
  btn.disabled = false;

  if (!r.ok || !r.data || !r.data.ok) {
    alert((r.data && (r.data.message || r.data.error)) || "Action failed.");
    return;
  }

  loadUsers();
});

addUserForm.addEventListener("submit", async (e) => {
  e.preventDefault();

  const email = toLowerEmail(newUserEmail.value);
  const role = (newUserRole.value || "captioner").trim();
  const password = newUserPassword.value || "";

  if (!email.endsWith("@3playmedia.com")) {
    alert("Email must be a @3playmedia.com address.");
    return;
  }

  if (role === "admin" && password.length < 8) {
    alert("Admin password required (min 8 chars).");
    return;
  }

  const r = await postJSON(API_ADMIN, {
    action: "users_add",
    email,
    role,
    password,
  });

  if (!r.ok || !r.data || !r.data.ok) {
    alert(
      (r.data && (r.data.message || r.data.error)) || "Failed to add user.",
    );
    return;
  }

  newUserEmail.value = "";
  newUserPassword.value = "";
  loadUsers();
});

// On load: if already admin session, show dashboard
(async function init() {
  updateFocusTracking();

  const isAdmin = await adminWhoAmI();
  if (isAdmin) showAdminDashboard();
})();
