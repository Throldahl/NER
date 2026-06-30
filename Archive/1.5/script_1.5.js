"use strict";

let highScore = "";
const validEmails = [
  "dthroldahl@3playmedia.com",
  "bpolen@3playmedia.com",
  "mmurray@3playmedia.com",
  "mmclaren@3playmedia.com",
  "aseimova@3playmedia.com",
  "dawson@3playmedia.com",
  "aostrowski@3playmedia.com",
  "csteiner@3playmedia.com",
  "erude@3playmedia.com",
  "elawson@3playmedia.com",
  "hayleyh@3playmedia.com",
  "iva@3playmedia.com",
  "jpotter@3playmedia.com",
  "jdaus@3playmedia.com",
  "jmckenzie@3playmedia.com",
  "khutchison@3playmedia.com",
  "lnesheim@3playmedia.com",
  "maria@3playmedia.com",
  "mpederson@3playmedia.com",
  "max@3playmedia.com",
  "nbudinski@3playmedia.com",
  "noah@3playmedia.com",
  "rwebb@3playmedia.com",
  "sgarrick@3playmedia.com",
  "vkelton@3playmedia.com",
];

let username = "";

const startBtn = document.getElementById("startBtn");
const emailInput = document.getElementById("email");
const errorMessage = document.getElementById("error-message");
const countdownSection = document.getElementById("countdown-section");
const countdownSpan = document.getElementById("countdown");
const videoSection = document.getElementById("video-section");
const video = document.getElementById("assessmentVideo");
const testAudioBtn = document.getElementById("testAudioBtn");
const testAudio = document.getElementById("testAudio");
const cancelCountdownBtn = document.getElementById("cancelCountdownBtn");
const audioVisualizer = document.getElementById("audioVisualizer");
const bars = audioVisualizer.querySelectorAll(".bar");

let countdownInterval;

const sendFeedback = function () {
  console.log("Feedback delivered");
  const data = {
    feedback: emailInput.value,
  };
  const xhr = new XMLHttpRequest();
  xhr.open("POST", "feedback.php", true);
  xhr.setRequestHeader("Content-Type", "application/json");
  xhr.send(JSON.stringify(data));
};

document.addEventListener("contextmenu", (event) => event.preventDefault());

document.addEventListener("keydown", (e) => {
  if (
    e.key === "F12" ||
    (e.ctrlKey && e.shiftKey && ["I", "J", "C", "U"].includes(e.key))
  ) {
    e.preventDefault();
  }
});

document.addEventListener("dragstart", (e) => e.preventDefault());

startBtn.addEventListener("click", () => {
  const email = emailInput.value.trim().toLowerCase();
  username = emailInput.value;

  if (!validEmails.includes(email)) {
    errorMessage.textContent =
      "Email address not recognized. Please try again.";
    return;
  }

  errorMessage.textContent = "";
  document.getElementById("form-section").classList.add("hidden");
  countdownSection.classList.remove("hidden");

  testAudio.pause();
  let countdown = 10;
  countdownSpan.textContent = countdown;

  countdownInterval = setInterval(() => {
    countdown--;
    countdownSpan.textContent = countdown;
    if (countdown <= 0) {
      clearInterval(countdownInterval);
      countdownSection.classList.add("hidden");
      playVideo();
      sendFeedback();
    }
  }, 1000);
});

cancelCountdownBtn.addEventListener("click", () => {
  clearInterval(countdownInterval);
  countdownSection.classList.add("hidden");
  document.getElementById("form-section").classList.remove("hidden");
});

function startBars() {
  bars.forEach((bar) => {
    bar.classList.add("playing");
    bar.style.height = ""; // remove inline override so animation takes over
  });
}

function stopBars() {
  bars.forEach((bar) => {
    bar.classList.remove("playing");
    bar.style.animation = "none"; // kill animation
    bar.offsetHeight; // force reflow
    bar.style.animation = ""; // reset animation so it can start next time
    bar.style.height = "5px"; // set flat
  });
}

testAudioBtn.addEventListener("click", () => {
  if (testAudio.paused) {
    testAudio.play();
    testAudioBtn.textContent = "Stop Audio";
    startBars();
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

function playVideo() {
  videoSection.classList.remove("hidden");

  // Lock video controls
  video.controls = false;
  video.currentTime = 0;
  video.play();

  // Prevent user interactions
  video.addEventListener("pause", () => {
    if (!video.ended) {
      video.play();
    }
  });
  video.addEventListener("contextmenu", (e) => {
    e.preventDefault();
  });
  video.addEventListener("seeking", () => {
    if (Math.abs(video.currentTime - 0) > 0.01) {
      video.currentTime = 0;
    }
  });
  video.addEventListener("ended", () => {
    // Optionally show a message when done
    alert("Assessment video has ended.");
  });
}

// STORE DATA
const storeData = function (msg, eml, pgld) {
  const data = {
    message: msg,
    email: eml,
    pageLoad: pgld,
  };
  const xhr = new XMLHttpRequest();
  xhr.open("POST", "dataStorage.php", true);
  xhr.setRequestHeader("Content-Type", "application/json");
  xhr.onreadystatechange = function () {
    if (xhr.readyState === 4 && xhr.status === 200) {
      // Handle the response from the server
      console.log("Server Response: ", xhr.responseText);
    }
  };
  xhr.send(JSON.stringify(data));
};
storeData("", "", "The page has been loaded.");

// RETREIVE HIGH SCORE
const getHighScore = function () {
  const xhr = new XMLHttpRequest();
  xhr.onreadystatechange = function () {
    if (xhr.readyState === 4 && xhr.status === 200) {
      highScore = xhr.responseText;
      console.log(`New high score from php: ${highScore}`);
      // document.querySelector(
      //   ".highScore"
      // ).innerHTML = `The high score is: ${highScore}`;
    } else
      console.error(
        "Error fetching highScores.php:",
        xhr.status,
        xhr.statusText,
      );
  };
  xhr.open("GET", "highScores.php", true);
  xhr.send();
};
// getHighScore();

// UPDATE HIGH SCORE
const sendHighScore = function (newHighScore) {
  highScore = newHighScore;
  const data = {
    number: highScore,
  };
  const xhr = new XMLHttpRequest();
  xhr.open("POST", "highScores.php", true);
  xhr.setRequestHeader("Content-Type", "application/json");
  xhr.send(JSON.stringify(data));
};

// MODAL OVERLAY
const modal = document.querySelector(".modal");
const overlay = document.querySelector(".overlay");
const btnCloseModal = document.querySelector(".btn--close-modal");
const modalContent = document.getElementById("modalContent");

const openModal = function (header, message) {
  // e.preventDefault();
  modal.classList.remove("hidden");
  overlay.classList.remove("hidden");
  modal__header.innerText = header;
  modalContent.innerHTML = message;
};

const closeModal = function () {
  modal.classList.add("hidden");
  overlay.classList.add("hidden");
};

btnCloseModal.addEventListener("click", closeModal);
overlay.addEventListener("click", closeModal);

document.addEventListener("keydown", function (e) {
  if (e.key === "Escape" && !modal.classList.contains("hidden")) {
    closeModal();
  }
});

// document.addEventListener("DOMContentLoaded", function () {
//   const currentDate = new Date();
//   const targetDate = new Date("2025-04-01");

//   if (currentDate < targetDate) {
//     openModal();
//   }
// });

// NEW LOAD
const getCentralTime = function () {
  const now = new Date();
  const options = {
    timeZone: "America/Chicago",
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
    hour: "2-digit",
    minute: "2-digit",
    second: "2-digit",
    hour12: true,
  };
  const centralTimeFormatter = new Intl.DateTimeFormat("en-US", options);
  // Format the current time according to the specified options
  const centralTimeString = centralTimeFormatter.format(now);
  return centralTimeString;
};

const newLoad = function (new_note) {
  const data = {
    timestamp: getCentralTime(),
    site: "SiteName",
    note: new_note,
  };
  const xhr = new XMLHttpRequest();
  xhr.open("POST", "../siteLoads.php", true);
  xhr.setRequestHeader("Content-Type", "application/json");
  xhr.send(JSON.stringify(data));
};
newLoad("");
