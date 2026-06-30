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
    site: "Thordle",
    note: new_note,
  };
  const xhr = new XMLHttpRequest();
  xhr.open("POST", "../siteLoads.php", true);
  xhr.setRequestHeader("Content-Type", "application/json");
  xhr.send(JSON.stringify(data));
};
newLoad("");
