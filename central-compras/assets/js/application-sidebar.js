(function() {
    var layout = document.getElementById('adminLayout');
    var toggle = document.getElementById('sidebarToggle');
    if (!layout || !toggle) return;

    var collapsed = window.matchMedia && window.matchMedia('(max-width: 680px)').matches;
    if (collapsed) layout.classList.add('sidebar-collapsed');

    function updateToggleState(isCollapsed) {
        toggle.setAttribute('aria-expanded', isCollapsed ? 'false' : 'true');
        toggle.setAttribute('aria-label', isCollapsed ? 'Expandir menu lateral' : 'Recolher menu lateral');
        var label = toggle.querySelector('.sidebar-toggle-label');
        if (label) label.textContent = isCollapsed ? 'Expandir menu' : 'Recolher menu';
    }

    updateToggleState(collapsed);
    toggle.addEventListener('click', function() {
        collapsed = layout.classList.toggle('sidebar-collapsed');
        updateToggleState(collapsed);
    });
}());
