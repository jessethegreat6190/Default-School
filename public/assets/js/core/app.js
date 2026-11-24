window.SchoolGate = window.SchoolGate || {};
(function (app) {
  app.on = function (event, handler) {
    document.addEventListener('schoolgate:' + event, (e) => handler(e.detail));
  };
  app.emit = function (event, detail) {
    document.dispatchEvent(new CustomEvent('schoolgate:' + event, { detail }));
  };
  document.addEventListener('DOMContentLoaded', function () {
    app.emit('ready', { page: document.body.dataset.page, school: window.SCHOOLGATE?.school });
  });
})(window.SchoolGate);

document.addEventListener("DOMContentLoaded", function () {
  var studentSel = document.getElementById("pickupStudent");
  var authSel = document.getElementById("pickupAuth");
  var map = window.SCHOOLGATE_PICKUPS || {};
  function fillAuth() {
    if (!studentSel || !authSel) return;
    var id = studentSel.value;
    authSel.innerHTML = '<option value="">-- unknown / not selected --</option>';
    (map[id] || []).forEach(function (p) {
      var o = document.createElement("option");
      o.value = p.id; o.textContent = p.name + (p.relationship ? " (" + p.relationship + ")" : "") + (p.pickup_code ? " ["+p.pickup_code+"]":"");
      o.dataset.name = p.name || ""; o.dataset.phone = p.phone || ""; o.dataset.idn = p.id_number || ""; o.dataset.rel = p.relationship || "";
      authSel.appendChild(o);
    });
  }
  if (studentSel) { studentSel.addEventListener("change", fillAuth); fillAuth(); }
  if (authSel) authSel.addEventListener("change", function(){
    var o = authSel.options[authSel.selectedIndex];
    if (!o || !o.dataset) return;
    if (o.dataset.name) document.getElementById("collectorName").value = o.dataset.name;
    if (o.dataset.phone) document.getElementById("collectorPhone").value = o.dataset.phone;
    if (o.dataset.idn) document.getElementById("collectorId").value = o.dataset.idn;
    if (o.dataset.rel) document.getElementById("collectorRel").value = o.dataset.rel;
  });
});
