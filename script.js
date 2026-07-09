"use strict";

const API_AUTH = "api_auth.php";
const API_METRICS = "api_metrics.php";
const API_ADMIN = "api_admin.php";

const byId = (id) => document.getElementById(id);

const siteTitle = byId("siteTitle");
const siteSubtitle = byId("siteSubtitle");
const sessionTools = byId("sessionTools");
const sessionEmail = byId("sessionEmail");
const logoutBtn = byId("logoutBtn");

const loginGate = byId("loginGate");
const loginEmail = byId("loginEmail");
const emailLoginBtn = byId("emailLoginBtn");
const googleSignInArea = byId("googleSignInArea");
const googleButton = byId("googleButton");
const loginMessage = byId("loginMessage");

const unassignedSection = byId("unassignedSection");
const assessmentApp = byId("assessmentApp");
const instructionsContent = byId("instructionsContent");
const prepContent = byId("prepContent");

const formSection = byId("form-section");
const startBtn = byId("startBtn");
const errorMessage = byId("error-message");
const countdownSection = byId("countdown-section");
const countdownSpan = byId("countdown");
const videoSection = byId("video-section");
const completionSection = byId("completion-section");
const completionTime = byId("completionTime");
const assessmentAudio = byId("assessmentAudio");
const assessmentVideo = byId("assessmentVideo");
const progressFill = byId("progressFill");
const timeCurrent = byId("timeCurrent");
const timeTotal = byId("timeTotal");
const playerLabel = byId("playerLabel");
const assessmentVisualizer = byId("assessmentVisualizer");
const assessmentBars = assessmentVisualizer.querySelectorAll(".bar");
const testAudioBtn = byId("testAudioBtn");
const testAudio = byId("testAudio");
const cancelCountdownBtn = byId("cancelCountdownBtn");
const audioVisualizer = byId("audioVisualizer");
const bars = audioVisualizer.querySelectorAll(".bar");

const adminDashboard = byId("adminDashboard");
const adminRefreshBtn = byId("adminRefreshBtn");
const tabButtons = document.querySelectorAll(".tabbtn");
const metricsTab = byId("metricsTab");
const activityTab = byId("activityTab");
const usersTab = byId("usersTab");
const testsTab = byId("testsTab");
const mediaTab = byId("mediaTab");
const metricsBody = byId("metricsBody");
const activityBody = byId("activityBody");
const usersBody = byId("usersBody");
const testsBody = byId("testsBody");
const mediaBody = byId("mediaBody");
const metricsTestFilter = byId("metricsTestFilter");
const activityTestFilter = byId("activityTestFilter");

const addUserForm = byId("addUserForm");
const newUserEmail = byId("newUserEmail");
const newUserRole = byId("newUserRole");
const newUserTest = byId("newUserTest");
const bulk3PlayTest = byId("bulk3PlayTest");
const bulk3PlayAssignBtn = byId("bulk3PlayAssignBtn");
const userSearch = byId("userSearch");
const userRoleFilter = byId("userRoleFilter");
const userStatusFilter = byId("userStatusFilter");
const userTestFilter = byId("userTestFilter");
const userCompletedFilter = byId("userCompletedFilter");

const testForm = byId("testForm");
const testIdInput = byId("testId");
const testTitle = byId("testTitle");
const testSubtitle = byId("testSubtitle");
const testAudioSelect = byId("testAudioSelect");
const sourceMediaSelect = byId("sourceMediaSelect");
const testInstructionsEditor = byId("testInstructionsEditor");
const testPrepEditor = byId("testPrepEditor");
const newTestBtn = byId("newTestBtn");

const mediaUploadForm = byId("mediaUploadForm");
const mediaLabel = byId("mediaLabel");
const mediaUsage = byId("mediaUsage");
const mediaFile = byId("mediaFile");
const mediaHelp = byId("mediaHelp");
const mediaUploadBtn = byId("mediaUploadBtn");
const uploadProgress = byId("uploadProgress");
const uploadProgressLabel = byId("uploadProgressLabel");
const uploadProgressPercent = byId("uploadProgressPercent");
const uploadProgressFill = byId("uploadProgressFill");

const toastRegion = byId("toastRegion");
const appModalOverlay = byId("appModalOverlay");
const appModal = byId("appModal");
const appModalTitle = byId("appModalTitle");
const appModalBody = byId("appModalBody");
const appModalActions = byId("appModalActions");
const appModalClose = byId("appModalClose");

let appConfig = {
  google_client_id: "",
  google_required_domain: "3playmedia.com",
  config_loaded: false,
  test_audio_max_mb: 50,
  source_media_max_mb: 500,
  php_upload_max_mb: 0,
  php_post_max_mb: 0,
  allowed_test_audio: ["mp3", "wav", "m4a", "aac"],
  allowed_source_media: ["mp3", "wav", "m4a", "aac", "mp4", "mov", "webm"],
};
let currentUser = null;
let currentTest = null;
let sessionId = null;
let adminTests = [];
let adminMedia = [];
let adminUsers = [];
let countdownInterval = null;
let googleRenderAttempted = false;
let modalResolver = null;

const pageLoadMs = Date.now();
let firstFocusMs = null;
let focusedMs = 0;
let focusStartMs = null;
let tabHiddenCount = 0;
let audioTestedBeforeStart = false;
let audioTestCount = 0;
let copyCountBeforeStart = 0;
let startClickedMs = null;

function makeClientSessionId() {
  if (window.crypto && crypto.randomUUID) return crypto.randomUUID();
  return (
    "cs_" + Math.random().toString(16).slice(2) + "_" + Date.now().toString(16)
  );
}
const clientSessionId = makeClientSessionId();

async function postJSON(url, payload) {
  const res = await fetch(url, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    credentials: "same-origin",
    body: JSON.stringify(payload),
  });
  const text = await res.text();
  try {
    return { ok: res.ok, status: res.status, data: JSON.parse(text) };
  } catch {
    return {
      ok: false,
      status: res.status,
      data: { error: "Non-JSON response", raw: text },
    };
  }
}

function postFormWithProgress(url, formData, onProgress) {
  return new Promise((resolve) => {
    const xhr = new XMLHttpRequest();
    xhr.open("POST", url, true);
    xhr.withCredentials = true;

    xhr.upload.onprogress = (event) => {
      if (!event.lengthComputable || typeof onProgress !== "function") return;
      onProgress(Math.round((event.loaded / event.total) * 100));
    };

    xhr.onload = () => {
      try {
        resolve({
          ok: xhr.status >= 200 && xhr.status < 300,
          status: xhr.status,
          data: JSON.parse(xhr.responseText || "{}"),
        });
      } catch {
        resolve({
          ok: false,
          status: xhr.status,
          data: { error: "Non-JSON response", raw: xhr.responseText || "" },
        });
      }
    };

    xhr.onerror = () => {
      resolve({
        ok: false,
        status: 0,
        data: { error: "Network error" },
      });
    };

    xhr.send(formData);
  });
}

function toLowerEmail(value) {
  return (value || "").trim().toLowerCase();
}

function isGoogleDomainEmail(email) {
  return toLowerEmail(email).endsWith(`@${appConfig.google_required_domain}`);
}

function escapeHtml(value) {
  return String(value ?? "")
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&#039;");
}

function fmtTs(ts) {
  if (!ts) return "";
  const d = new Date(String(ts).replace(" ", "T"));
  if (Number.isNaN(d.getTime())) return ts;
  return d.toLocaleString();
}

function seconds(ms) {
  if (!ms && ms !== 0) return "";
  return Math.round(Number(ms) / 1000);
}

function fmtBytes(bytes) {
  const n = Number(bytes || 0);
  if (n >= 1073741824) return `${(n / 1073741824).toFixed(1)} GB`;
  if (n >= 1048576) return `${(n / 1048576).toFixed(1)} MB`;
  if (n >= 1024) return `${(n / 1024).toFixed(1)} KB`;
  return `${n} B`;
}

function uploadRulesForUsage(usage) {
  if (usage === "test_audio") {
    return {
      extensions: appConfig.allowed_test_audio,
      appMaxBytes: Number(appConfig.test_audio_max_mb || 0) * 1048576,
    };
  }
  return {
    extensions: appConfig.allowed_source_media,
    appMaxBytes: Number(appConfig.source_media_max_mb || 0) * 1048576,
  };
}

function fileExtension(file) {
  return ((file && file.name ? file.name : "").split(".").pop() || "").toLowerCase();
}

function isVideoUploadExtension(ext) {
  return ["mp4", "mov", "webm"].includes(ext);
}

function mediaUsageForFile(file) {
  const ext = fileExtension(file);
  return isVideoUploadExtension(ext) ? "source" : mediaUsage.value;
}

function serverUploadLimitBytes() {
  const limits = [appConfig.php_upload_max_mb, appConfig.php_post_max_mb]
    .map(Number)
    .filter((n) => n > 0)
    .map((n) => n * 1048576);
  return limits.length ? Math.min(...limits) : 0;
}

function setUploadProgress(visible, percent = 0, label = "Uploading...") {
  uploadProgress.classList.toggle("hidden", !visible);
  uploadProgressLabel.textContent = label;
  uploadProgressPercent.textContent = `${Math.max(0, Math.min(100, percent))}%`;
  uploadProgressFill.style.width = `${Math.max(0, Math.min(100, percent))}%`;
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

function setMessage(el, text, ok = false) {
  el.textContent = text || "";
  el.classList.toggle("ok", ok);
}

function responseMessage(data, fallback) {
  if (!data) return fallback;
  if (data.error === "Non-JSON response" && data.raw) {
    return "The server rejected the request before the app could process it. The file may exceed Hostinger/PHP upload limits.";
  }
  if (data.message) return data.message;
  if (data.detail) return `${data.error || "Server error."} ${data.detail}`;
  if (data.error) return data.error;
  return fallback;
}

function notify(message, type = "info") {
  if (!message) return;
  const toast = document.createElement("div");
  toast.className = `toast toast--${type}`;
  toast.textContent = message;
  toastRegion.appendChild(toast);

  window.setTimeout(() => {
    toast.classList.add("toast--leaving");
    window.setTimeout(() => toast.remove(), 180);
  }, type === "error" ? 5200 : 3400);
}

function closeAppModal(result = null) {
  appModal.classList.add("hidden");
  appModalOverlay.classList.add("hidden");
  document.body.classList.remove("modal-open");
  appModalBody.innerHTML = "";
  appModalActions.innerHTML = "";
  if (modalResolver) {
    const resolve = modalResolver;
    modalResolver = null;
    resolve(result);
  }
}

function showAppModal({ title, bodyHtml, actions = [] }) {
  appModalTitle.textContent = title || "";
  appModalBody.innerHTML = bodyHtml || "";
  appModalActions.innerHTML = "";

  const modalActions = actions.length
    ? actions
    : [{ label: "Close", value: null, className: "btn--primary" }];

  modalActions.forEach((action) => {
    const btn = document.createElement("button");
    btn.type = "button";
    btn.className = action.className || "btn--ghost";
    btn.textContent = action.label;
    btn.addEventListener("click", () => closeAppModal(action.value));
    appModalActions.appendChild(btn);
  });

  document.body.classList.add("modal-open");
  appModalOverlay.classList.remove("hidden");
  appModal.classList.remove("hidden");
  appModal.querySelector("button")?.focus();

  return new Promise((resolve) => {
    modalResolver = resolve;
  });
}

function confirmAction(message, options = {}) {
  const {
    title = "Confirm",
    confirmLabel = "Continue",
    cancelLabel = "Cancel",
    danger = false,
  } = options;

  return showAppModal({
    title,
    bodyHtml: `<p>${escapeHtml(message)}</p>`,
    actions: [
      { label: cancelLabel, value: false, className: "btn--ghost" },
      {
        label: confirmLabel,
        value: true,
        className: danger ? "btn--danger" : "btn--primary",
      },
    ],
  });
}

appModalClose.addEventListener("click", () => closeAppModal(null));
appModalOverlay.addEventListener("click", () => closeAppModal(null));
document.addEventListener("keydown", (e) => {
  if (e.key === "Escape" && !appModal.classList.contains("hidden")) {
    closeAppModal(null);
  }
});

function pageIsActive() {
  return document.visibilityState === "visible" && document.hasFocus();
}

function updateFocusTracking() {
  const active = pageIsActive();
  if (active && firstFocusMs === null) firstFocusMs = Date.now() - pageLoadMs;

  if (startClickedMs !== null) {
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
  if (document.visibilityState === "hidden" && startClickedMs === null) {
    tabHiddenCount += 1;
  }
  updateFocusTracking();
});
document.addEventListener("copy", () => {
  if (startClickedMs === null) copyCountBeforeStart += 1;
});

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
    gain.gain.setValueAtTime(0.0001, t);
    gain.gain.linearRampToValueAtTime(volume, t + 0.005);
    gain.gain.exponentialRampToValueAtTime(0.0001, t + durationSec);
    osc.start(t);
    osc.stop(t + durationSec + 0.02);
  } catch {
    // Some browsers block audio contexts until a gesture. The countdown still works.
  }
}

function primeBeepAudio() {
  try {
    const ctx = getBeepCtx();
    if (ctx.state === "suspended") ctx.resume().catch(() => {});
    const osc = ctx.createOscillator();
    const gain = ctx.createGain();
    gain.gain.value = 0;
    osc.connect(gain);
    gain.connect(ctx.destination);
    osc.start();
    osc.stop(ctx.currentTime + 0.01);
  } catch {
    // Ignore audio unlock failures.
  }
}

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

function startAssessmentBars() {
  assessmentBars.forEach((bar) => bar.classList.add("playing"));
}

function stopAssessmentBars() {
  assessmentBars.forEach((bar) => {
    bar.classList.remove("playing");
    bar.style.height = "6px";
  });
}

function computePrestartMetrics() {
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

function showLogin() {
  loginGate.classList.remove("hidden");
  unassignedSection.classList.add("hidden");
  assessmentApp.classList.add("hidden");
  adminDashboard.classList.add("hidden");
  sessionTools.classList.add("hidden");
}

function resetAssessmentState() {
  clearInterval(countdownInterval);
  countdownInterval = null;
  testAudio.pause();
  [assessmentAudio, assessmentVideo].forEach((media) => {
    media.onpause = null;
    media.onseeking = null;
    media.ontimeupdate = null;
    media.onloadedmetadata = null;
    media.ondurationchange = null;
    media.onended = null;
    media.pause();
    try {
      media.currentTime = 0;
    } catch {
      // Media may not be seekable before metadata loads.
    }
  });
  sessionId = null;
  focusedMs = 0;
  focusStartMs = null;
  tabHiddenCount = 0;
  audioTestedBeforeStart = false;
  audioTestCount = 0;
  copyCountBeforeStart = 0;
  startClickedMs = null;
  countdownSection.classList.add("hidden");
  videoSection.classList.add("hidden");
  completionSection.classList.add("hidden");
  completionTime.textContent = "";
  formSection.classList.remove("hidden");
  startBtn.disabled = false;
  progressFill.style.width = "0%";
  timeCurrent.textContent = "0:00";
  timeTotal.textContent = "--:--";
  stopBars();
  stopAssessmentBars();
}

function showCompletion(recorded = true) {
  formSection.classList.add("hidden");
  countdownSection.classList.add("hidden");
  videoSection.classList.add("hidden");
  completionSection.classList.remove("hidden");
  completionTime.textContent = recorded
    ? `Recorded ${new Date().toLocaleString()}`
    : "The media ended, but completion could not be confirmed. Please contact your coordinator.";
}

function renderCurrentTest(test) {
  currentTest = test;
  document.title = test.title || "Captioner NER Testing";
  siteTitle.textContent = test.title || "Captioner NER Testing";
  siteSubtitle.textContent = test.subtitle || "Internal assessment";
  instructionsContent.innerHTML = test.instructions_html || "";
  prepContent.innerHTML = test.prep_html || "";

  testAudio.src = test.test_audio_url || "";
  testAudio.load();
  testAudioBtn.disabled = !test.test_audio_url;

  assessmentAudio.removeAttribute("src");
  assessmentVideo.removeAttribute("src");
  assessmentAudio.load();
  assessmentVideo.load();

  if (test.source_media_kind === "video") {
    assessmentVideo.src = test.source_media_url || "";
    assessmentVideo.classList.remove("hidden");
    assessmentVisualizer.classList.add("hidden");
    playerLabel.textContent = "Playing assessment video...";
  } else {
    assessmentAudio.src = test.source_media_url || "";
    assessmentVideo.classList.add("hidden");
    assessmentVisualizer.classList.remove("hidden");
    playerLabel.textContent = "Playing assessment audio...";
  }

  assessmentApp.classList.remove("hidden");
  unassignedSection.classList.add("hidden");
}

function currentAssessmentMedia() {
  return currentTest && currentTest.source_media_kind === "video"
    ? assessmentVideo
    : assessmentAudio;
}

async function startMetricsSession() {
  if (!currentTest || sessionId) return;
  const r = await postJSON(API_METRICS, {
    action: "session_start",
    client_session_id: clientSessionId,
    user_agent: navigator.userAgent,
    viewport_w: window.innerWidth,
    viewport_h: window.innerHeight,
    first_focus_ms: firstFocusMs,
  });
  if (r.ok && r.data && r.data.ok) {
    sessionId = r.data.session_id;
  } else {
    setMessage(
      errorMessage,
      (r.data && (r.data.message || r.data.error)) ||
        "Failed to start session logging.",
    );
  }
}

function applyAuthenticatedState(payload) {
  currentUser = payload.user;
  currentTest = payload.test || null;
  loginGate.classList.add("hidden");
  sessionTools.classList.remove("hidden");
  sessionEmail.textContent = currentUser.email;

  if (currentTest) {
    resetAssessmentState();
    renderCurrentTest(currentTest);
    startMetricsSession();
  } else {
    assessmentApp.classList.add("hidden");
    unassignedSection.classList.remove("hidden");
  }

  if (currentUser.role === "admin") {
    showAdminDashboard();
  } else {
    adminDashboard.classList.add("hidden");
  }
}

async function emailLogin() {
  const email = toLowerEmail(loginEmail.value);
  setMessage(loginMessage, "");
  if (!email) {
    setMessage(loginMessage, "Enter your email address.");
    return;
  }

  if (isGoogleDomainEmail(email)) {
    googleSignInArea.classList.remove("hidden");
    renderGoogleButton();
    setMessage(
      loginMessage,
      appConfig.google_client_id
        ? `Use Google Sign-In for @${appConfig.google_required_domain} accounts.`
        : appConfig.config_loaded
          ? "Google Sign-In is required, but the client ID is not configured yet."
          : "Google Sign-In configuration could not be loaded. Refresh and try again.",
    );
    return;
  }

  emailLoginBtn.disabled = true;
  const r = await postJSON(API_AUTH, { action: "email_login", email });
  emailLoginBtn.disabled = false;
  if (!r.ok || !r.data || !r.data.ok) {
    setMessage(
      loginMessage,
      responseMessage(r.data, "Access denied."),
    );
    return;
  }
  applyAuthenticatedState(r.data);
}

async function googleLogin(credential) {
  setMessage(loginMessage, "Checking Google account...", true);
  const r = await postJSON(API_AUTH, {
    action: "google_login",
    credential,
  });
  if (!r.ok || !r.data || !r.data.ok) {
    setMessage(
      loginMessage,
      responseMessage(r.data, "Google login failed."),
    );
    return;
  }
  setMessage(loginMessage, "");
  applyAuthenticatedState(r.data);
}

window.handleGoogleCredential = (response) => {
  if (response && response.credential) googleLogin(response.credential);
};

function renderGoogleButton() {
  if (!appConfig.google_client_id || googleRenderAttempted) return;
  if (!window.google || !window.google.accounts || !window.google.accounts.id) {
    window.setTimeout(renderGoogleButton, 250);
    return;
  }
  googleRenderAttempted = true;
  googleSignInArea.classList.remove("hidden");
  window.google.accounts.id.initialize({
    client_id: appConfig.google_client_id,
    callback: window.handleGoogleCredential,
  });
  window.google.accounts.id.renderButton(googleButton, {
    theme: "outline",
    size: "large",
    type: "standard",
    text: "continue_with",
    shape: "rectangular",
    width: 320,
  });
}

async function loadConfig() {
  const r = await postJSON(API_AUTH, { action: "config" });
  if (r.ok && r.data && r.data.ok) {
    appConfig = { ...appConfig, ...r.data, config_loaded: true };
    const serverCaps = [appConfig.php_upload_max_mb, appConfig.php_post_max_mb]
      .map(Number)
      .filter((n) => n > 0);
    const serverCap = serverCaps.length ? Math.min(...serverCaps) : 0;
    mediaHelp.textContent = `Test audio: ${appConfig.allowed_test_audio.join(", ")} up to ${appConfig.test_audio_max_mb} MB. Source media: ${appConfig.allowed_source_media.join(", ")} up to ${appConfig.source_media_max_mb} MB.${serverCap ? ` Server cap: ${serverCap} MB per upload.` : ""}`;
    renderGoogleButton();
  }
}

testAudioBtn.addEventListener("click", async () => {
  if (!testAudio.src) return;
  if (testAudio.paused) {
    await testAudio.play();
    testAudioBtn.textContent = "Stop Audio";
    startBars();
    if (startClickedMs === null) {
      audioTestedBeforeStart = true;
      audioTestCount += 1;
      if (sessionId) {
        postJSON(API_METRICS, {
          action: "audio_tested",
          session_id: sessionId,
        });
      }
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

function sendFeedbackStartedEmail() {
  if (!currentUser || !currentUser.email) return;
  const xhr = new XMLHttpRequest();
  xhr.open("POST", "feedback.php", true);
  xhr.setRequestHeader("Content-Type", "application/json");
  xhr.send(JSON.stringify({ feedback: currentUser.email }));
}

function playAssessmentMedia() {
  const media = currentAssessmentMedia();
  if (!media || !media.src) {
    setMessage(errorMessage, "No assessment source media is assigned.");
    return;
  }

  videoSection.classList.remove("hidden");
  media.controls = false;
  media.currentTime = 0;

  let lastAllowedTime = 0;
  const updateProgress = () => {
    const dur = media.duration;
    const cur = media.currentTime;
    lastAllowedTime = Math.max(lastAllowedTime, cur);
    if (Number.isFinite(dur) && dur > 0) {
      const pct = Math.max(0, Math.min(1, cur / dur)) * 100;
      progressFill.style.width = pct.toFixed(2) + "%";
      timeCurrent.textContent = fmtTime(cur);
      timeTotal.textContent = fmtTime(dur);
    } else {
      timeCurrent.textContent = fmtTime(cur);
      timeTotal.textContent = "--:--";
    }
  };

  media.onpause = () => {
    if (!media.ended) media.play().catch(() => {});
  };
  media.onseeking = () => {
    if (!media.ended && Math.abs(media.currentTime - lastAllowedTime) > 1) {
      media.currentTime = lastAllowedTime;
    }
  };
  media.ontimeupdate = updateProgress;
  media.onloadedmetadata = updateProgress;
  media.ondurationchange = updateProgress;
  media.onended = async () => {
    stopAssessmentBars();
    progressFill.style.width = "100%";
    let recorded = false;
    if (sessionId) {
      try {
        const r = await postJSON(API_METRICS, {
          action: "video_ended",
          session_id: sessionId,
        });
        recorded = !!(r.ok && r.data && r.data.ok);
      } catch {
        recorded = false;
      }
    }
    showCompletion(recorded);
    notify(
      recorded
        ? "Assessment completed. Your completion has been recorded."
        : "Assessment ended, but completion could not be confirmed.",
      recorded ? "success" : "error",
    );
  };

  media.play().catch(() => {
    playerLabel.textContent = "Click Start Assessment again to begin media...";
  });
  if (currentTest && currentTest.source_media_kind !== "video") {
    startAssessmentBars();
  }
}

startBtn.addEventListener("click", async () => {
  if (!currentTest) return;
  if (!sessionId) await startMetricsSession();
  if (!sessionId) return;

  startBtn.disabled = true;
  setMessage(errorMessage, "");
  primeBeepAudio();
  updateFocusTracking();

  startClickedMs = Date.now();
  await updatePrestartOnServer({
    start_clicked_client_ms: startClickedMs,
    countdown_cancelled: 0,
  });

  formSection.classList.add("hidden");
  completionSection.classList.add("hidden");
  countdownSection.classList.remove("hidden");
  testAudio.pause();
  stopBars();
  testAudioBtn.textContent = "Test Audio";

  let countdown = 10;
  countdownSpan.textContent = countdown;
  beepOnce(740, 0.08, 0.08);

  countdownInterval = setInterval(async () => {
    countdown -= 1;
    countdownSpan.textContent = countdown;
    if (countdown > 0) beepOnce(740, 0.08, 0.08);
    else beepOnce(880, 0.08, 0.03);

    if (countdown <= 0) {
      clearInterval(countdownInterval);
      countdownSection.classList.add("hidden");
      playAssessmentMedia();
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
  formSection.classList.remove("hidden");
  startBtn.disabled = false;
  await updatePrestartOnServer({ countdown_cancelled: 1 });
  startClickedMs = null;
});

logoutBtn.addEventListener("click", async () => {
  await postJSON(API_AUTH, { action: "logout" });
  currentUser = null;
  currentTest = null;
  resetAssessmentState();
  showLogin();
});

emailLoginBtn.addEventListener("click", emailLogin);
loginEmail.addEventListener("keydown", (e) => {
  if (e.key === "Enter") {
    e.preventDefault();
    emailLogin();
  }
});

function activeAdminTabId() {
  return document.querySelector(".tabbtn.active")?.getAttribute("data-tab") || "metricsTab";
}

function showAdminTab(tabId) {
  [metricsTab, activityTab, usersTab, testsTab, mediaTab].forEach((tab) => {
    tab.classList.toggle("hidden", tab.id !== tabId);
  });
  tabButtons.forEach((btn) => {
    btn.classList.toggle("active", btn.getAttribute("data-tab") === tabId);
  });
}

async function showAdminDashboard() {
  adminDashboard.classList.remove("hidden");
  await loadAdminReferenceData();
  await refreshActiveAdminTab();
}

async function refreshActiveAdminTab() {
  const tab = activeAdminTabId();
  if (tab === "metricsTab") await loadMetrics();
  if (tab === "activityTab") await loadActivity();
  if (tab === "usersTab") await loadUsers();
  if (tab === "testsTab") renderTestsTable();
  if (tab === "mediaTab") renderMediaTable();
}

tabButtons.forEach((btn) => {
  btn.addEventListener("click", async () => {
    showAdminTab(btn.getAttribute("data-tab"));
    await refreshActiveAdminTab();
  });
});

adminRefreshBtn.addEventListener("click", async () => {
  await loadAdminReferenceData();
  await refreshActiveAdminTab();
});

metricsTestFilter.addEventListener("change", loadMetrics);
activityTestFilter.addEventListener("change", loadActivity);
userSearch.addEventListener("input", renderUsersTable);
userRoleFilter.addEventListener("change", renderUsersTable);
userStatusFilter.addEventListener("change", renderUsersTable);
userTestFilter.addEventListener("change", renderUsersTable);
userCompletedFilter.addEventListener("change", renderUsersTable);

async function loadAdminReferenceData() {
  const [testsRes, mediaRes] = await Promise.all([
    postJSON(API_ADMIN, { action: "tests_list" }),
    postJSON(API_ADMIN, { action: "media_list" }),
  ]);
  if (testsRes.ok && testsRes.data && testsRes.data.ok) {
    adminTests = testsRes.data.tests || [];
  }
  if (mediaRes.ok && mediaRes.data && mediaRes.data.ok) {
    adminMedia = mediaRes.data.media || [];
  }
  populateAllSelects();
}

function populateTestSelect(select, selected = "", includeAll = false, blankLabel = "Unassigned") {
  const value = String(selected || "");
  const options = [];
  if (includeAll) options.push(`<option value="0">All tests</option>`);
  else options.push(`<option value="">${escapeHtml(blankLabel)}</option>`);
  adminTests.forEach((test) => {
    options.push(
      `<option value="${test.id}" ${String(test.id) === value ? "selected" : ""}>${escapeHtml(test.title)}</option>`,
    );
  });
  select.innerHTML = options.join("");
}

function populateMediaSelect(select, usageKind, selected = "") {
  const value = String(selected || "");
  const list = adminMedia.filter((m) => m.usage_kind === usageKind);
  const options = [`<option value="">None selected</option>`];
  list.forEach((media) => {
    options.push(
      `<option value="${media.id}" ${String(media.id) === value ? "selected" : ""}>${escapeHtml(media.label)}</option>`,
    );
  });
  select.innerHTML = options.join("");
}

function populateUserTestFilter() {
  const value = userTestFilter.value || "0";
  const options = [
    `<option value="0">All tests</option>`,
    `<option value="__unassigned">Unassigned</option>`,
  ];
  adminTests.forEach((test) => {
    options.push(
      `<option value="${test.id}" ${String(test.id) === value ? "selected" : ""}>${escapeHtml(test.title)}</option>`,
    );
  });
  userTestFilter.innerHTML = options.join("");
  userTestFilter.value = [...userTestFilter.options].some((opt) => opt.value === value)
    ? value
    : "0";
}

function populateAllSelects() {
  populateTestSelect(metricsTestFilter, metricsTestFilter.value, true);
  populateTestSelect(activityTestFilter, activityTestFilter.value, true);
  populateTestSelect(newUserTest, newUserTest.value, false);
  populateTestSelect(bulk3PlayTest, bulk3PlayTest.value, false, "Choose a test");
  populateUserTestFilter();
  populateMediaSelect(testAudioSelect, "test_audio", testAudioSelect.value);
  populateMediaSelect(sourceMediaSelect, "source", sourceMediaSelect.value);
}

async function loadMetrics() {
  metricsBody.innerHTML = `<tr><td colspan="12" class="subtle">Loading...</td></tr>`;
  const r = await postJSON(API_ADMIN, {
    action: "metrics_users_rollup",
    limit: 2000,
    test_id: Number(metricsTestFilter.value || 0),
  });

  if (!r.ok || !r.data || !r.data.ok) {
    metricsBody.innerHTML = `<tr><td colspan="12" style="color:#fecaca">Failed to load metrics.</td></tr>`;
    return;
  }
  const rows = r.data.rows || [];
  if (!rows.length) {
    metricsBody.innerHTML = `<tr><td colspan="12" class="subtle">No metrics found.</td></tr>`;
    return;
  }

  metricsBody.innerHTML = rows
    .map((s) => {
      const hasSession = !!s.created_at;
      let status = "Not started";
      if (hasSession) status = "Loaded";
      if (s.start_clicked_at) status = "Started";
      if (s.video_started_at) status = "In progress";
      if (s.video_ended_at || s.completed_at) status = "Finished";
      if (Number(s.countdown_cancelled) === 1 && !s.video_started_at) {
        status = "Cancelled";
      }
      return `
        <tr>
          <td>${escapeHtml(s.email)}</td>
          <td>${escapeHtml(s.assigned_test_title || "Unassigned")}</td>
          <td>${escapeHtml(s.session_test_title || "")}</td>
          <td><span class="badge">${status}</span></td>
          <td>${fmtTs(s.created_at)}</td>
          <td>${fmtTs(s.start_clicked_at)}</td>
          <td>${badgeYesNo(s.audio_tested_before_start)}</td>
          <td>${s.copy_count_before_start || 0}</td>
          <td>${hasSession ? seconds(s.focus_ms_before_start || 0) : ""}</td>
          <td>${hasSession ? seconds(s.wall_ms_before_start || 0) : ""}</td>
          <td>${s.tab_hidden_count_before_start || 0}</td>
          <td>${badgeYesNo(s.video_ended_at || s.completed_at)}</td>
        </tr>
      `;
    })
    .join("");
}

async function loadActivity() {
  activityBody.innerHTML = `<tr><td colspan="4" class="subtle">Loading...</td></tr>`;
  const r = await postJSON(API_ADMIN, {
    action: "activity_list",
    limit: 500,
    test_id: Number(activityTestFilter.value || 0),
  });
  if (!r.ok || !r.data || !r.data.ok) {
    activityBody.innerHTML = `<tr><td colspan="4" style="color:#fecaca">Failed to load activity.</td></tr>`;
    return;
  }
  const events = r.data.events || [];
  if (!events.length) {
    activityBody.innerHTML = `<tr><td colspan="4" class="subtle">No activity found.</td></tr>`;
    return;
  }
  activityBody.innerHTML = events
    .map(
      (event) => `
        <tr>
          <td>${fmtTs(event.created_at)}</td>
          <td>${escapeHtml(event.user_email || "")}</td>
          <td>${escapeHtml(event.test_title || "")}</td>
          <td>${escapeHtml(event.event_label || event.event_type)}</td>
        </tr>
      `,
    )
    .join("");
}

async function loadUsers() {
  usersBody.innerHTML = `<tr><td colspan="8" class="subtle">Loading...</td></tr>`;
  const r = await postJSON(API_ADMIN, { action: "users_list", limit: 2000 });
  if (!r.ok || !r.data || !r.data.ok) {
    usersBody.innerHTML = `<tr><td colspan="8" style="color:#fecaca">Failed to load users.</td></tr>`;
    return;
  }
  adminUsers = r.data.users || [];
  renderUsersTable();
}

function filteredUsers() {
  const query = toLowerEmail(userSearch.value);
  const role = userRoleFilter.value;
  const status = userStatusFilter.value;
  const testId = userTestFilter.value || "0";
  const completed = userCompletedFilter.value;

  return adminUsers.filter((u) => {
    const active = Number(u.is_active) === 1;
    const hasCompleted = Number(u.has_completed) === 1;
    if (query && !toLowerEmail(u.email).includes(query)) return false;
    if (role && u.role !== role) return false;
    if (status === "active" && !active) return false;
    if (status === "inactive" && active) return false;
    if (testId === "__unassigned" && u.test_id) return false;
    if (testId !== "0" && testId !== "__unassigned" && String(u.test_id || "") !== testId) return false;
    if (completed === "yes" && !hasCompleted) return false;
    if (completed === "no" && hasCompleted) return false;
    return true;
  });
}

function renderUsersTable() {
  if (!adminUsers.length) {
    usersBody.innerHTML = `<tr><td colspan="8" class="subtle">No users found.</td></tr>`;
    return;
  }

  const users = filteredUsers();
  if (!users.length) {
    usersBody.innerHTML = `<tr><td colspan="8" class="subtle">No users match these filters.</td></tr>`;
    return;
  }

  usersBody.innerHTML = users
    .map((u) => {
      const active = Number(u.is_active) === 1;
      const hasCompleted = Number(u.has_completed) === 1;
      return `
        <tr data-userid="${u.id}">
          <td>${escapeHtml(u.email)}</td>
          <td>
            <select class="table-select" data-field="role">
              <option value="captioner" ${u.role === "captioner" ? "selected" : ""}>captioner</option>
              <option value="admin" ${u.role === "admin" ? "selected" : ""}>admin</option>
            </select>
          </td>
          <td>${testSelectMarkup(u.test_id, "test_id")}</td>
          <td>
            <select class="table-select" data-field="is_active">
              <option value="1" ${active ? "selected" : ""}>Active</option>
              <option value="0" ${!active ? "selected" : ""}>Inactive</option>
            </select>
          </td>
          <td>${badgeYesNo(hasCompleted)}</td>
          <td>${fmtTs(u.last_login_at)}</td>
          <td>${fmtTs(u.created_at)}</td>
          <td>
            <button class="btn--primary small" type="button" data-action="save-user">Save</button>
          </td>
        </tr>
      `;
    })
    .join("");
}

function testSelectMarkup(selected, field) {
  const value = String(selected || "");
  const options = [`<option value="">Unassigned</option>`];
  adminTests.forEach((test) => {
    options.push(
      `<option value="${test.id}" ${String(test.id) === value ? "selected" : ""}>${escapeHtml(test.title)}</option>`,
    );
  });
  return `<select class="table-select" data-field="${field}">${options.join("")}</select>`;
}

usersBody.addEventListener("click", async (e) => {
  const btn = e.target.closest("button[data-action='save-user']");
  if (!btn) return;
  const tr = btn.closest("tr[data-userid]");
  const userId = tr.getAttribute("data-userid");
  const role = tr.querySelector("[data-field='role']").value;
  const testId = tr.querySelector("[data-field='test_id']").value;
  const isActive = tr.querySelector("[data-field='is_active']").value;
  btn.disabled = true;
  const r = await postJSON(API_ADMIN, {
    action: "users_update",
    user_id: userId,
    role,
    test_id: testId,
    is_active: Number(isActive),
  });
  btn.disabled = false;
  if (!r.ok || !r.data || !r.data.ok) {
    notify(responseMessage(r.data, "User update failed."), "error");
    return;
  }
  notify("User updated.", "success");
  await loadUsers();
});

addUserForm.addEventListener("submit", async (e) => {
  e.preventDefault();
  const email = toLowerEmail(newUserEmail.value);
  const role = newUserRole.value || "captioner";
  const testId = newUserTest.value || "";
  const r = await postJSON(API_ADMIN, {
    action: "users_add",
    email,
    role,
    test_id: testId,
  });
  if (!r.ok || !r.data || !r.data.ok) {
    notify(responseMessage(r.data, "Failed to add user."), "error");
    return;
  }
  newUserEmail.value = "";
  newUserRole.value = "captioner";
  newUserTest.value = "";
  notify("User added or reactivated.", "success");
  await loadUsers();
});

bulk3PlayAssignBtn.addEventListener("click", async () => {
  const testId = bulk3PlayTest.value || "";
  const test = adminTests.find((t) => String(t.id) === String(testId));
  if (!testId || !test) {
    notify("Choose a test first.", "error");
    return;
  }

  const confirmed = await confirmAction(
    `Assign all @${appConfig.google_required_domain} users to "${test.title}"?`,
    {
      title: "Bulk Assign Users",
      confirmLabel: "Assign Users",
    },
  );
  if (!confirmed) {
    return;
  }

  bulk3PlayAssignBtn.disabled = true;
  const r = await postJSON(API_ADMIN, {
    action: "users_bulk_assign_3play",
    test_id: testId,
  });
  bulk3PlayAssignBtn.disabled = false;

  if (!r.ok || !r.data || !r.data.ok) {
    notify(responseMessage(r.data, "Bulk assignment failed."), "error");
    return;
  }

  const matched = Number(r.data.matched_count || 0);
  const changed = Number(r.data.changed_count || 0);
  notify(`Assigned "${test.title}" to ${matched} @${appConfig.google_required_domain} user(s). ${changed} row(s) changed.`, "success");
  await loadAdminReferenceData();
  await loadUsers();
});

function mediaById(id) {
  return adminMedia.find((media) => String(media.id) === String(id || ""));
}

function mediaPreviewMarkup(media, label) {
  if (!media || !media.file_path) {
    return `<p class="subtle">No ${escapeHtml(label)} selected.</p>`;
  }

  const source = escapeHtml(media.file_path);
  const title = escapeHtml(media.label || media.original_name || label);
  if (media.media_kind === "video") {
    return `
      <p class="subtle">${title}</p>
      <video class="preview-media" controls preload="metadata" src="${source}"></video>
    `;
  }

  return `
    <p class="subtle">${title}</p>
    <audio class="preview-audio" controls preload="metadata" src="${source}"></audio>
  `;
}

function previewTest(test) {
  const testAudio = mediaById(test.test_audio_media_id);
  const sourceMedia = mediaById(test.source_media_id);
  showAppModal({
    title: `Preview: ${test.title || "Untitled Test"}`,
    bodyHtml: `
      <div class="test-preview">
        ${test.subtitle ? `<p class="subtle">${escapeHtml(test.subtitle)}</p>` : ""}
        <div class="preview-section">
          <h4>Instructions</h4>
          <div class="rich-content">${test.instructions_html || ""}</div>
        </div>
        <div class="preview-section">
          <h4>Prep</h4>
          <div class="rich-content">${test.prep_html || ""}</div>
        </div>
        <div class="preview-section">
          <h4>Test Audio</h4>
          ${mediaPreviewMarkup(testAudio, "test audio")}
        </div>
        <div class="preview-section">
          <h4>Assessment Source</h4>
          ${mediaPreviewMarkup(sourceMedia, "assessment source")}
        </div>
      </div>
    `,
    actions: [{ label: "Close", value: null, className: "btn--primary" }],
  });
}

function renderTestsTable() {
  if (!adminTests.length) {
    testsBody.innerHTML = `<tr><td colspan="6" class="subtle">No tests found.</td></tr>`;
    return;
  }
  testsBody.innerHTML = adminTests
    .map(
      (test) => `
        <tr>
          <td>${escapeHtml(test.title)}</td>
          <td>${escapeHtml(test.test_audio_label || "")}</td>
          <td>${escapeHtml(test.source_media_label || "")}</td>
          <td>${test.assigned_count || 0}</td>
          <td>${fmtTs(test.updated_at || test.created_at)}</td>
          <td>
            <div class="table-actions">
              <button class="btn--ghost small" type="button" data-action="preview-test" data-testid="${test.id}">Preview</button>
              <button class="btn--ghost small" type="button" data-action="edit-test" data-testid="${test.id}">Edit</button>
              <button class="btn--danger small" type="button" data-action="delete-test" data-testid="${test.id}">Delete</button>
            </div>
          </td>
        </tr>
      `,
    )
    .join("");
}

function clearTestForm() {
  testIdInput.value = "";
  testTitle.value = "";
  testSubtitle.value = "";
  testAudioSelect.value = "";
  sourceMediaSelect.value = "";
  testInstructionsEditor.innerHTML = "";
  testPrepEditor.innerHTML = "";
}

newTestBtn.addEventListener("click", clearTestForm);

testsBody.addEventListener("click", async (e) => {
  const btn = e.target.closest("button[data-action]");
  if (!btn) return;
  const action = btn.getAttribute("data-action");
  const id = Number(btn.getAttribute("data-testid"));
  const test = adminTests.find((t) => Number(t.id) === id);
  if (!test) return;

  if (action === "preview-test") {
    previewTest(test);
    return;
  }

  if (action === "edit-test") {
    testIdInput.value = test.id;
    testTitle.value = test.title || "";
    testSubtitle.value = test.subtitle || "";
    testAudioSelect.value = test.test_audio_media_id || "";
    sourceMediaSelect.value = test.source_media_id || "";
    testInstructionsEditor.innerHTML = test.instructions_html || "";
    testPrepEditor.innerHTML = test.prep_html || "";
    testForm.scrollIntoView({ behavior: "smooth", block: "start" });
    return;
  }

  if (action === "delete-test") {
    const confirmed = await confirmAction(`Delete "${test.title}"?`, {
      title: "Delete Test",
      confirmLabel: "Delete",
      danger: true,
    });
    if (!confirmed) return;
    btn.disabled = true;
    let r = await postJSON(API_ADMIN, {
      action: "tests_delete",
      test_id: id,
    });
    if (
      r.status === 409 &&
      r.data &&
      r.data.requires_confirm
    ) {
      const forceDelete = await confirmAction(
        `${r.data.message} Delete anyway and set those users to unassigned?`,
        {
          title: "Test Is Assigned",
          confirmLabel: "Delete Anyway",
          danger: true,
        },
      );
      if (forceDelete) {
        r = await postJSON(API_ADMIN, {
          action: "tests_delete",
          test_id: id,
          force: 1,
        });
      } else {
        btn.disabled = false;
        return;
      }
    }
    btn.disabled = false;
    if (!r.ok || !r.data || !r.data.ok) {
      notify(responseMessage(r.data, "Delete failed."), "error");
      return;
    }
    await loadAdminReferenceData();
    renderTestsTable();
    notify("Test deleted.", "success");
  }
});

testForm.addEventListener("submit", async (e) => {
  e.preventDefault();
  const payload = {
    action: "tests_save",
    test_id: testIdInput.value || 0,
    title: testTitle.value,
    subtitle: testSubtitle.value,
    test_audio_media_id: testAudioSelect.value || null,
    source_media_id: sourceMediaSelect.value || null,
    instructions_html: testInstructionsEditor.innerHTML,
    prep_html: testPrepEditor.innerHTML,
  };
  const r = await postJSON(API_ADMIN, payload);
  if (!r.ok || !r.data || !r.data.ok) {
    notify(responseMessage(r.data, "Failed to save test."), "error");
    return;
  }
  clearTestForm();
  await loadAdminReferenceData();
  renderTestsTable();
  notify("Test saved.", "success");
});

document.querySelectorAll(".editor-toolbar button[data-command]").forEach((btn) => {
  btn.addEventListener("click", () => {
    const toolbar = btn.closest(".editor-toolbar");
    const editor = byId(toolbar.getAttribute("data-editor"));
    editor.focus();
    document.execCommand(btn.getAttribute("data-command"), false, null);
  });
});

function renderMediaTable() {
  if (!adminMedia.length) {
    mediaBody.innerHTML = `<tr><td colspan="7" class="subtle">No media found.</td></tr>`;
    return;
  }
  mediaBody.innerHTML = adminMedia
    .map((media) => {
      const canDelete = Number(media.is_builtin) !== 1;
      return `
        <tr>
          <td>${escapeHtml(media.label)}</td>
          <td>${media.usage_kind === "test_audio" ? "Test Audio" : "Source"}</td>
          <td>${escapeHtml(media.media_kind)}</td>
          <td>${fmtBytes(media.size_bytes)}</td>
          <td class="mono-ish">${escapeHtml(media.file_path)}</td>
          <td>${fmtTs(media.created_at)}</td>
          <td>
            ${
              canDelete
                ? `<button class="btn--danger small" type="button" data-action="delete-media" data-mediaid="${media.id}">Delete</button>`
                : `<span class="badge">Built-in</span>`
            }
          </td>
        </tr>
      `;
    })
    .join("");
}

mediaUploadForm.addEventListener("submit", async (e) => {
  e.preventDefault();
  if (!mediaFile.files || !mediaFile.files[0]) {
    notify("Choose a media file to upload.", "error");
    return;
  }
  const file = mediaFile.files[0];
  const usage = mediaUsageForFile(file);
  mediaUsage.value = usage;
  const ext = fileExtension(file);
  const rules = uploadRulesForUsage(usage);
  if (!rules.extensions.includes(ext)) {
    notify(`Unsupported file type for ${usage === "source" ? "Assessment source" : "Test Audio button"}. Allowed: ${rules.extensions.join(", ")}.`, "error");
    return;
  }
  if (rules.appMaxBytes > 0 && file.size > rules.appMaxBytes) {
    notify(`This file is too large for this upload type. Limit: ${fmtBytes(rules.appMaxBytes)}.`, "error");
    return;
  }
  const serverMaxBytes = serverUploadLimitBytes();
  if (serverMaxBytes > 0 && file.size > serverMaxBytes) {
    notify(`This file is larger than the server upload limit of ${fmtBytes(serverMaxBytes)}. Increase Hostinger/PHP upload limits or choose a smaller file.`, "error");
    return;
  }

  const formData = new FormData();
  formData.append("action", "media_upload");
  formData.append("label", mediaLabel.value);
  formData.append("usage_kind", usage);
  formData.append("media_file", file);
  mediaUploadBtn.disabled = true;
  mediaUploadBtn.textContent = "Uploading...";
  setUploadProgress(true, 0, `Uploading ${file.name}`);
  const r = await postFormWithProgress(API_ADMIN, formData, (percent) => {
    setUploadProgress(true, percent, `Uploading ${file.name}`);
  });
  mediaUploadBtn.disabled = false;
  mediaUploadBtn.textContent = "Upload";
  if (!r.ok || !r.data || !r.data.ok) {
    setUploadProgress(false);
    notify(responseMessage(r.data, "Upload failed."), "error");
    return;
  }
  setUploadProgress(true, 100, "Upload complete");
  mediaLabel.value = "";
  mediaFile.value = "";
  await loadAdminReferenceData();
  renderMediaTable();
  notify("Media uploaded.", "success");
  window.setTimeout(() => setUploadProgress(false), 900);
});

mediaFile.addEventListener("change", () => {
  const file = mediaFile.files && mediaFile.files[0];
  if (file && isVideoUploadExtension(fileExtension(file))) {
    mediaUsage.value = "source";
  }
});

mediaBody.addEventListener("click", async (e) => {
  const btn = e.target.closest("button[data-action='delete-media']");
  if (!btn) return;
  const confirmed = await confirmAction("Delete this uploaded media file?", {
    title: "Delete Media",
    confirmLabel: "Delete",
    danger: true,
  });
  if (!confirmed) return;
  btn.disabled = true;
  const r = await postJSON(API_ADMIN, {
    action: "media_delete",
    media_id: btn.getAttribute("data-mediaid"),
  });
  btn.disabled = false;
  if (!r.ok || !r.data || !r.data.ok) {
    notify(responseMessage(r.data, "Delete failed."), "error");
    return;
  }
  await loadAdminReferenceData();
  renderMediaTable();
  notify("Media deleted.", "success");
});

(async function init() {
  updateFocusTracking();
  await loadConfig();
  const r = await postJSON(API_AUTH, { action: "whoami" });
  if (r.ok && r.data && r.data.ok) {
    applyAuthenticatedState(r.data);
  } else {
    showLogin();
  }
})();
