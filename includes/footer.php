        </main>
    </div>

    <!-- Scripts -->
    <script>
        // Toggle sidebar
        document.getElementById('toggleSidebar').addEventListener('click', function() {
            document.querySelector('.sidebar').classList.toggle('collapsed');
        });

        // Toggle submenu
        function toggleSubmenu(submenuId, button) {
            const submenu = document.getElementById(submenuId);
            const icon = button.querySelector('i.fa-chevron-down');
            
            submenu.classList.toggle('active');
            submenu.style.maxHeight = submenu.classList.contains('active') ? submenu.scrollHeight + 'px' : '0';
            
            // Toggle chevron icon
            if (icon) {
                icon.classList.toggle('transform');
                icon.classList.toggle('rotate-180');
            }
        }

        // Close all other submenus when one is opened
        document.querySelectorAll('[onclick^="toggleSubmenu"]').forEach(button => {
            button.addEventListener('click', function(e) {
                // Don't close if clicking on a link inside the button
                if (e.target.tagName === 'A' || e.target.tagName === 'I' || e.target.tagName === 'SPAN') {
                    return;
                }
                
                const currentSubmenu = this.getAttribute('onclick').match(/'([^']+)'/)[1];
                document.querySelectorAll('.submenu').forEach(menu => {
                    if (menu.id !== currentSubmenu) {
                        menu.classList.remove('active');
                        menu.style.maxHeight = '0';
                    }
                });
            });
        });

        // Close submenus when clicking outside
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.submenu') && !e.target.closest('[onclick^="toggleSubmenu"]')) {
                document.querySelectorAll('.submenu').forEach(menu => {
                    menu.classList.remove('active');
                    menu.style.maxHeight = '0';
                });
            }
        });
    </script>
</body>
</html>
