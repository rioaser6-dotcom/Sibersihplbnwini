(function () {
    'use strict';

    function initUserSidebar() {
        var sidebar = document.getElementById('sidebar');
        var overlay = document.getElementById('sidebarOverlay');
        var toggle = document.getElementById('menuToggle');
        var closeBtn = document.getElementById('closeSidebarBtn');

        if (!sidebar || !overlay) {
            return;
        }

        function openSidebar() {
            sidebar.classList.add('open');
            overlay.classList.add('show');
            overlay.classList.add('active');

            if (toggle) {
                toggle.setAttribute('aria-expanded', 'true');
            }

            document.body.style.overflow = 'hidden';
        }

        function closeSidebar() {
            sidebar.classList.remove('open');
            overlay.classList.remove('show');
            overlay.classList.remove('active');

            if (toggle) {
                toggle.setAttribute('aria-expanded', 'false');
            }

            document.body.style.overflow = '';
        }

        if (toggle) {
            toggle.addEventListener('click', function (event) {
                event.stopPropagation();

                if (sidebar.classList.contains('open')) {
                    closeSidebar();
                } else {
                    openSidebar();
                }
            });
        }

        if (closeBtn) {
            closeBtn.addEventListener('click', closeSidebar);
        }

        overlay.addEventListener('click', closeSidebar);

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && sidebar.classList.contains('open')) {
                closeSidebar();
            }
        });

        window.addEventListener('resize', function () {
            if (window.innerWidth > 768) {
                closeSidebar();
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initUserSidebar);
    } else {
        initUserSidebar();
    }
})();
