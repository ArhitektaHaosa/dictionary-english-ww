(function () {
  var input = document.querySelector("[data-suggest]");
  var list = document.querySelector("[data-suggest-list]");
  if (!input || !list) return;

  var timer = 0;
  var controller = null;
  var items = [];
  var active = -1;

  function hide() {
    list.hidden = true;
    list.innerHTML = "";
    items = [];
    active = -1;
    input.setAttribute("aria-expanded", "false");
  }

  function choose(index) {
    var item = items[index];
    if (!item) return;
    window.location.href = "/dictionary/english/" + encodeURIComponent(item.slug);
  }

  function render(rows) {
    list.innerHTML = "";
    items = rows;
    active = -1;
    if (!rows.length) {
      hide();
      return;
    }
    rows.forEach(function (row, index) {
      var button = document.createElement("button");
      button.type = "button";
      button.textContent = row.headword;
      button.addEventListener("mousedown", function (event) {
        event.preventDefault();
        choose(index);
      });
      list.appendChild(button);
    });
    list.hidden = false;
    input.setAttribute("aria-expanded", "true");
  }

  input.setAttribute("role", "combobox");
  input.setAttribute("aria-autocomplete", "list");
  input.setAttribute("aria-expanded", "false");
  list.setAttribute("role", "listbox");

  input.addEventListener("input", function () {
    window.clearTimeout(timer);
    var query = input.value.trim();
    if (query.length < 1) {
      hide();
      return;
    }
    timer = window.setTimeout(function () {
      if (controller) controller.abort();
      controller = new AbortController();
      fetch("/api/suggest.php?q=" + encodeURIComponent(query), { signal: controller.signal })
        .then(function (response) {
          if (!response.ok) throw new Error("suggest failed");
          return response.json();
        })
        .then(function (rows) {
          if (!Array.isArray(rows)) {
            hide();
            return;
          }
          render(rows);
        })
        .catch(function (error) {
          if (error.name !== "AbortError") hide();
        });
    }, 140);
  });

  input.addEventListener("keydown", function (event) {
    if (list.hidden) return;
    if (event.key === "ArrowDown") {
      event.preventDefault();
      active = Math.min(items.length - 1, active + 1);
    } else if (event.key === "ArrowUp") {
      event.preventDefault();
      active = Math.max(0, active - 1);
    } else if (event.key === "Escape") {
      hide();
      return;
    } else if (event.key === "Enter" && active >= 0) {
      event.preventDefault();
      choose(active);
      return;
    } else {
      return;
    }
    Array.prototype.forEach.call(list.children, function (node, index) {
      node.classList.toggle("is-active", index === active);
    });
  });

  input.addEventListener("blur", function () {
    window.setTimeout(hide, 120);
  });
})();
