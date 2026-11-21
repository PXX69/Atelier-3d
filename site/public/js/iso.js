/* Liagos 3D : le terrain de jeu de l'accueil. Une grille isométrique où l'on empile des blocs,
   comme dans l'application. Souris, toucher et clavier (flèches + Entrée). */
(function () {
  "use strict";
  var svg = document.getElementById("iso");
  if (!svg) return;
  var hint = document.getElementById("hint"), tools = document.querySelector("#play .tools");
  var NS = "http://www.w3.org/2000/svg";
  var N = 5, W = 40, H = 20, Z = 40, X0 = 260, Y0 = 150, MAX = 4;
  var PAL = [["#ffc08c", "#ff8a3d", "#d9520b"], ["#a8f3e4", "#2cc4ab", "#13917f"], ["#d5dee7", "#93a6b8", "#6b7f92"]];
  var stacks = [], groups = [], mode = "add", color = 0, active = [1, 1], cursor, touched = false;

  function pts(a) { return a.map(function (p) { return p[0] + "," + p[1]; }).join(" "); }
  function total() { var n = 0; stacks.forEach(function (c) { c.forEach(function (s) { n += s.length; }); }); return n; }
  function plural(n) { return n + (n > 1 ? " blocs" : " bloc"); }
  function say(t) { if (hint) hint.textContent = t; }

  /* Dessine une case : le sol, puis ses blocs de bas en haut. Les blocs à partir de l'étage « from » tombent. */
  function render(i, j, from, delay) {
    var cx = X0 + (i - j) * W, cy = Y0 + (i + j) * H, s = stacks[i][j];
    var out = '<polygon class="ground" points="' + pts([[cx, cy], [cx + W, cy + H], [cx, cy + 2 * H], [cx - W, cy + H]]) + '"/>';
    for (var k = 0; k < s.length; k++) {
      var t = cy - (k + 1) * Z, b = cy - k * Z, p = PAL[s[k]];
      var top = pts([[cx, t], [cx + W, t + H], [cx, t + 2 * H], [cx - W, t + H]]);
      var left = pts([[cx - W, t + H], [cx, t + 2 * H], [cx, b + 2 * H], [cx - W, b + H]]);
      var right = pts([[cx, t + 2 * H], [cx + W, t + H], [cx + W, b + H], [cx, b + 2 * H]]);
      var drop = k >= from;
      out += '<g class="blk' + (drop ? " drop" : "") + '"' + (drop ? ' style="animation-delay:' + (delay + (k - from) * 90) + 'ms"' : "") + ">" +
        '<polygon points="' + left + '" fill="' + p[1] + '" stroke="' + p[1] + '"/>' +
        '<polygon points="' + right + '" fill="' + p[2] + '" stroke="' + p[2] + '"/>' +
        '<polygon points="' + top + '" fill="' + p[0] + '" stroke="' + p[0] + '"/></g>';
    }
    var g = groups[i][j];
    g.innerHTML = out;
    g.setAttribute("aria-label", "Case " + (i + 1) + "-" + (j + 1) + " : " + (s.length ? plural(s.length) : "vide"));
  }

  function place() {
    var i = active[0], j = active[1], cx = X0 + (i - j) * W, cy = Y0 + (i + j) * H;
    cursor.setAttribute("points", pts([[cx, cy], [cx + W, cy + H], [cx, cy + 2 * H], [cx - W, cy + H]]));
    groups.forEach(function (row) { row.forEach(function (g) { g.setAttribute("tabindex", "-1"); }); });
    groups[i][j].setAttribute("tabindex", "0");
  }

  function act(i, j, m) {
    var s = stacks[i][j];
    if (m === "add") {
      if (s.length >= MAX) { say("Cette pile est à sa hauteur maximale (" + MAX + " blocs)."); return; }
      s.push(color); render(i, j, s.length - 1, 0);
    } else {
      if (!s.length) { say("Il n'y a rien à retirer ici."); return; }
      s.pop(); render(i, j, 99, 0);
    }
    touched = true;
    var n = total();
    say(n ? plural(n) + " posé" + (n > 1 ? "s" : "") + ". Cliquez sur la grille pour en ajouter, ou choisissez « Retirer »."
          : "La grille est vide. Cliquez dessus pour poser un bloc.");
  }

  function build() {
    svg.innerHTML = "";
    var order = [], i, j;
    for (i = 0; i < N; i++) { stacks[i] = []; groups[i] = []; for (j = 0; j < N; j++) { stacks[i][j] = []; order.push([i, j]); } }
    stacks[1][1] = [0, 0, 0]; stacks[2][1] = [0]; stacks[3][1] = [1];      /* la composition du logo */
    order.sort(function (a, b) { return (a[0] + a[1]) - (b[0] + b[1]) || a[0] - b[0]; });   /* de l'arrière vers l'avant */
    order.forEach(function (c, n) {
      var g = document.createElementNS(NS, "g");
      g.setAttribute("class", "cell"); g.setAttribute("role", "button");
      g.setAttribute("data-i", c[0]); g.setAttribute("data-j", c[1]);
      svg.appendChild(g); groups[c[0]][c[1]] = g;
      render(c[0], c[1], 0, 120 + n * 45);
    });
    cursor = document.createElementNS(NS, "polygon");
    cursor.setAttribute("class", "cursor"); svg.appendChild(cursor);
    place();
  }

  function cellOf(e) { var g = e.target.closest ? e.target.closest(".cell") : null; return g ? [+g.getAttribute("data-i"), +g.getAttribute("data-j")] : null; }

  svg.addEventListener("click", function (e) {
    var c = cellOf(e); if (!c) return;
    svg.classList.remove("kb"); active = c; place(); act(c[0], c[1], mode);
  });
  svg.addEventListener("keydown", function (e) {
    var c = cellOf(e); if (!c) return;
    var moves = { ArrowRight: [1, 0], ArrowLeft: [-1, 0], ArrowDown: [0, 1], ArrowUp: [0, -1] }, d = moves[e.key];
    if (d) {
      e.preventDefault(); svg.classList.add("kb");
      active = [Math.max(0, Math.min(N - 1, c[0] + d[0])), Math.max(0, Math.min(N - 1, c[1] + d[1]))];
      place(); groups[active[0]][active[1]].focus();
      say("Case " + (active[0] + 1) + "-" + (active[1] + 1) + " : " + (stacks[active[0]][active[1]].length ? plural(stacks[active[0]][active[1]].length) : "vide") + ".");
    } else if (e.key === "Enter" || e.key === " ") { e.preventDefault(); svg.classList.add("kb"); act(c[0], c[1], mode); }
    else if (e.key === "Delete" || e.key === "Backspace") { e.preventDefault(); svg.classList.add("kb"); act(c[0], c[1], "del"); }
  });

  if (tools) tools.addEventListener("click", function (e) {
    var b = e.target.closest("button"); if (!b) return;
    var m = b.getAttribute("data-mode"), c = b.getAttribute("data-c"), a = b.getAttribute("data-act");
    if (m) { mode = m; tools.querySelectorAll("[data-mode]").forEach(function (x) { x.setAttribute("aria-pressed", x === b ? "true" : "false"); }); }
    else if (c !== null) { color = +c; tools.querySelectorAll("[data-c]").forEach(function (x) { x.setAttribute("aria-pressed", x === b ? "true" : "false"); }); }
    else if (a === "rand") {
      var free = []; stacks.forEach(function (r, i) { r.forEach(function (s, j) { if (s.length < MAX) free.push([i, j]); }); });
      if (!free.length) { say("La grille est pleine : retirez un bloc."); return; }
      var p = free[Math.floor(Math.random() * free.length)], keep = color;
      color = Math.floor(Math.random() * PAL.length); act(p[0], p[1], "add"); color = keep;
    } else if (a === "clear") {
      for (var i = 0; i < N; i++) for (var j = 0; j < N; j++) { stacks[i][j] = []; render(i, j, 99, 0); }
      touched = true; say("La grille est vide. Cliquez dessus pour poser un bloc.");
    }
  });

  build();
})();
