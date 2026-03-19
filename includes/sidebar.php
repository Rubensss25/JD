<style>
  /* smooth sidebar animations */
  .sidebar-item {
    transition: all 0.2s ease;
  }
  /* collapsible sidebar state */
  .sidebar-collapsed .sidebar-text {
    display: none;
  }
  .sidebar-collapsed .sidebar-item {
    justify-content: center;
    padding-left: 0;
    padding-right: 0;
  }
  .sidebar-collapsed .jd-logo span {
    margin-right: 0;
  }
  /* active menu highlight - enhanced */
  .menu-active {
    background: linear-gradient(90deg, rgba(15, 111, 148, 0.12) 0%, rgba(15, 111, 148, 0.05) 100%);
    border-left: 4px solid #0f6f94;
    color: #05445E;
    font-weight: 500;
    position: relative;
  }
  .menu-active::after {
    content: '';
    position: absolute;
    right: 0;
    top: 50%;
    transform: translateY(-50%);
    width: 3px;
    height: 60%;
    background: #0f6f94;
    border-radius: 3px 0 0 3px;
    opacity: 0.6;
  }
  /* hover effect enhancement */
  .sidebar-item:not(.menu-active):hover {
    background: linear-gradient(90deg, #d4edf7 0%, rgba(212, 237, 247, 0.5) 100%);
    transform: translateX(4px);
  }
  /* mobile sidebar drawer styles */
  .mobile-sidebar {
    position: fixed;
    top: 0;
    left: -100%;
    width: 280px;
    height: 100vh;
    background: rgba(255, 255, 255, 0.98);
    backdrop-filter: blur(10px);
    z-index: 9999;
    transition: left 0.3s ease;
    box-shadow: 4px 0 20px rgba(0, 0, 0, 0.15);
  }
  .mobile-sidebar.active {
    left: 0;
  }
  .mobile-overlay {
    position: fixed;
    top: 0;
    left: 0;
    width: 100vw;
    height: 100vh;
    background: rgba(0, 0, 0, 0.5);
    z-index: 9998;
    opacity: 0;
    visibility: hidden;
    transition: opacity 0.3s ease, visibility 0.3s ease;
  }
  .mobile-overlay.active {
    opacity: 1;
    visibility: visible;
  }
  /* mobile menu button */
  .mobile-menu-btn {
    display: none;
  }
  
  /* Logout Modal Styles */
  .modal-overlay {
    position: fixed;
    top: 0;
    left: 0;
    width: 100vw;
    height: 100vh;
    background: rgba(0, 0, 0, 0.5);
    backdrop-filter: blur(4px);
    z-index: 10000;
    display: flex;
    align-items: center;
    justify-content: center;
    opacity: 0;
    visibility: hidden;
    transition: opacity 0.3s ease, visibility 0.3s ease;
  }
  
  .modal-overlay.active {
    opacity: 1;
    visibility: visible;
  }
  
  .modal-container {
    background: white;
    border-radius: 1rem;
    width: 90%;
    max-width: 400px;
    transform: scale(0.9);
    transition: transform 0.3s ease;
    box-shadow: 0 20px 40px rgba(0, 0, 0, 0.2);
  }
  
  .modal-overlay.active .modal-container {
    transform: scale(1);
  }
  
  .modal-header {
    padding: 1.5rem 1.5rem 0.5rem 1.5rem;
  }
  
  .modal-body {
    padding: 1rem 1.5rem;
  }
  
  .modal-footer {
    padding: 1.5rem;
    display: flex;
    gap: 1rem;
    justify-content: flex-end;
  }
  
  .modal-btn {
    padding: 0.75rem 1.5rem;
    border-radius: 0.75rem;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.2s ease;
    border: none;
    font-size: 1rem;
  }
  
  .modal-btn-cancel {
    background: #f1f5f9;
    color: #475569;
  }
  
  .modal-btn-cancel:hover {
    background: #e2e8f0;
  }
  
  .modal-btn-confirm {
    background: #b03e3e;
    color: white;
  }
  
  .modal-btn-confirm:hover {
    background: #8b2e2e;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(176, 62, 62, 0.3);
  }
  
  .modal-icon {
    width: 64px;
    height: 64px;
    background: #fee2e2;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 1rem auto;
  }
  
  .modal-icon span {
    font-size: 2.5rem;
    color: #b03e3e;
  }
  
  .modal-title {
    font-size: 1.5rem;
    font-weight: 600;
    color: #1e293b;
    text-align: center;
    margin-bottom: 0.5rem;
  }
  
  .modal-message {
    color: #64748b;
    text-align: center;
    line-height: 1.5;
  }
  
  /* responsive breakpoints */
  @media (max-width: 1023px) {
    .mobile-menu-btn {
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 0.5rem;
      border-radius: 0.5rem;
      background: rgba(255, 255, 255, 0.1);
      border: 1px solid rgba(255, 255, 255, 0.2);
      color: #0f6f94;
    }
    .mobile-menu-btn:hover {
      background: rgba(255, 255, 255, 0.2);
    }
  }
</style>

<!-- Mobile Menu Button (visible on mobile/tablet) -->
<button id="mobileMenuBtn" class="mobile-menu-btn fixed top-0 left-0 z-50 lg:hidden p-4">
  <span class="material-symbols-outlined text-2xl">menu</span>
</button>

<!-- Mobile Sidebar Drawer -->
<div id="mobileOverlay" class="mobile-overlay lg:hidden" onclick="toggleMobileSidebar()"></div>
<aside id="mobileSidebar" class="mobile-sidebar lg:hidden">
  <div class="flex flex-col h-full">
    <!-- mobile header -->  
    <div class="flex items-center justify-between p-4 border-b border-gray-200">
      <div class="flex items-center gap-2">
        <img src="../assets/images/logo.jpg" alt="JD Water Logo" class="w-10 h-10 object-contain">
        <div class="leading-tight">
          <span class="text-lg font-semibold text-[#05445E]">JD Water Refilling </span>
          <p class="text-xs text-[#0f6f94]/70">Supplies Store manager</p>
        </div>
      </div>
      <button onclick="toggleMobileSidebar()" class="p-2 rounded-full hover:bg-gray-100">
        <span class="material-symbols-outlined text-gray-600">close</span>
      </button>
    </div>
    
    <!-- mobile navigation with dynamic active state -->
    <nav class="flex-1 overflow-y-auto p-4 space-y-2" id="mobileNav">
      <!-- Navigation items will be populated by JavaScript -->
    </nav>
    
    <!-- Logout button for mobile (with modal trigger) -->
    <div class="p-4 border-t border-[#b3dff0]">
      <button onclick="showLogoutModal()" class="flex items-center gap-4 py-3 px-4 w-full rounded-xl text-[#b03e3e] hover:bg-[#ffe6e6] transition-all">
        <span class="material-symbols-outlined text-[#b03e3e]">logout</span>
        <span class="text-base">Logout</span>
      </button>
    </div>
  </div>
</aside>

<!-- Desktop Sidebar (hidden on mobile/tablet) -->
<aside id="sidebar" class="hidden lg:flex lg:flex-col w-72 bg-white/95 backdrop-blur-sm border-r border-white/30 shadow-xl h-screen sticky top-0 transition-all duration-300 overflow-hidden" style="box-shadow: 8px 0 20px -10px rgba(0,100,130,0.2);">
  <!-- sidebar inner -->
  <div class="flex flex-col h-full px-4 py-6">
    <!-- logo area with water drop -->
    <div class="flex items-center gap-2 px-2 mb-8">
      <img src="../assets/images/logo.jpg" alt="JD Water Logo" class="w-12 h-12 object-contain">
      <div class="leading-tight">
        <span class="text-xl font-semibold text-[#05445E]">JD Water Refilling </span>
        <p class="text-xs text-[#0f6f94]/70">Supplies Store manager</p>
      </div>
    </div>
    
    <!-- navigation menu with dynamic active state -->
    <nav class="flex-1 space-y-1" id="desktopNav">
      <!-- Navigation items will be populated by JavaScript -->
    </nav>
    
    <!-- Logout at bottom (with modal trigger) -->
    <div class="mt-auto pt-6 border-t border-[#b3dff0]">
      <button onclick="showLogoutModal()" class="sidebar-item flex items-center gap-4 py-3 px-4 w-full rounded-xl text-[#b03e3e] hover:bg-[#ffe6e6] transition-all">
        <span class="material-symbols-outlined text-[#b03e3e]">logout</span>
        <span class="sidebar-text text-base">Logout</span>
      </button>
    </div>
  </div>
</aside>

<!-- Logout Confirmation Modal -->
<div id="logoutModal" class="modal-overlay">
  <div class="modal-container">
    <div class="modal-header">
      <div class="modal-icon">
        <span class="material-symbols-outlined">logout</span>
      </div>
      <h3 class="modal-title">Logout Confirmation</h3>
    </div>
    <div class="modal-body">
      <p class="modal-message">
        Are you sure you want to logout from JD Water Station Manager?<br>
        <span class="text-sm text-gray-400 mt-2 block">You'll need to login again to access the dashboard.</span>
      </p>
    </div>
    <div class="modal-footer">
      <button onclick="hideLogoutModal()" class="modal-btn modal-btn-cancel">
        Cancel
      </button>
      <button onclick="confirmLogout()" class="modal-btn modal-btn-confirm">
        Yes, Logout
      </button>
    </div>
  </div>
</div>

<script>
  // Navigation items configuration
  const navItems = [
    { href: 'dashboard.php', icon: 'dashboard', label: 'Dashboard' },
    { href: 'inventory.php', icon: 'inventory', label: 'Inventory' },
    { href: 'sales.php', icon: 'point_of_sale', label: 'Sales' },
    { href: 'reports.php', icon: 'description', label: 'Report' },
    { href: 'registry.php', icon: 'app_registration', label: 'Registry' },
    { href: 'systemsettings.php', icon: 'settings', label: 'System Settings' }
  ];

  // Function to get current page filename
  function getCurrentPage() {
    const path = window.location.pathname;
    return path.split('/').pop() || 'dashboard.php'; // Default to dashboard if no file
  }

  // Function to generate navigation HTML
  function generateNavHTML(isMobile = false) {
    const currentPage = getCurrentPage();
    let html = '';

    navItems.forEach(item => {
      const isActive = currentPage === item.href;
      const activeClass = isActive ? 'menu-active' : '';
      
      // Different styling for mobile vs desktop
      if (isMobile) {
        html += `
          <a href="${item.href}" class="flex items-center gap-4 py-3 px-4 rounded-xl text-[#1b5c72] hover:bg-[#d4edf7] transition-all ${activeClass}">
            <span class="material-symbols-outlined text-[#0f6f94]">${item.icon}</span>
            <span class="text-base ${isActive ? 'font-medium' : ''}">${item.label}</span>
          </a>
        `;
      } else {
        html += `
          <a href="${item.href}" class="sidebar-item flex items-center gap-4 py-3 px-4 rounded-xl text-[#1b5c72] hover:bg-[#d4edf7] transition-all ${activeClass}">
            <span class="material-symbols-outlined text-[#0f6f94]">${item.icon}</span>
            <span class="sidebar-text text-base ${isActive ? 'font-medium' : ''}">${item.label}</span>
          </a>
        `;
      }
    });

    return html;
  }

  // Logout Modal Functions
  function showLogoutModal() {
    const modal = document.getElementById('logoutModal');
    modal.classList.add('active');
    // Prevent body scroll when modal is open
    document.body.style.overflow = 'hidden';
  }

  function hideLogoutModal() {
    const modal = document.getElementById('logoutModal');
    modal.classList.remove('active');
    // Restore body scroll
    document.body.style.overflow = '';
  }

  function confirmLogout() {
    // Add any pre-logout cleanup here (like clearing session storage, etc.)
    sessionStorage.clear();
    localStorage.removeItem('userSession'); // if you're using localStorage
    
    // Redirect to logout handler to destroy server session
    window.location.href = 'logout.php';
    
    // Optional: You can also show a loading state before redirect
    // document.body.innerHTML += '<div class="loading-spinner">Logging out...</div>';
  }

  // Close modal when clicking outside (optional - uncomment if you want this behavior)
  // document.getElementById('logoutModal').addEventListener('click', function(e) {
  //   if (e.target === this) {
  //     hideLogoutModal();
  //   }
  // });

  // Initialize navigation
  document.addEventListener('DOMContentLoaded', function() {
    // Populate desktop navigation
    const desktopNav = document.getElementById('desktopNav');
    if (desktopNav) {
      desktopNav.innerHTML = generateNavHTML(false);
    }

    // Populate mobile navigation
    const mobileNav = document.getElementById('mobileNav');
    if (mobileNav) {
      mobileNav.innerHTML = generateNavHTML(true);
    }

    // Mobile menu button click event
    const mobileMenuBtn = document.getElementById('mobileMenuBtn');
    if (mobileMenuBtn) {
      mobileMenuBtn.addEventListener('click', function(e) {
        e.stopPropagation();
        toggleMobileSidebar();
      });
    }

    // Sidebar collapse functionality
    const collapseBtn = document.getElementById('collapseSidebar');
    const sidebar = document.getElementById('sidebar');
    
    if (collapseBtn && sidebar) {
      collapseBtn.addEventListener('click', function() {
        sidebar.classList.toggle('sidebar-collapsed');
        if (sidebar.classList.contains('sidebar-collapsed')) {
          sidebar.style.width = '80px';
          collapseBtn.innerHTML = '<span class="material-symbols-outlined">menu</span>';
        } else {
          sidebar.style.width = '288px'; // w-72 = 18rem = 288px
          collapseBtn.innerHTML = '<span class="material-symbols-outlined">menu_open</span>';
        }
      });
    }

    // Handle escape key to close modal
    document.addEventListener('keydown', function(e) {
      if (e.key === 'Escape') {
        hideLogoutModal();
      }
    });
  });

  // Mobile sidebar toggle function
  function toggleMobileSidebar() {
    const sidebar = document.getElementById('mobileSidebar');
    const overlay = document.getElementById('mobileOverlay');
    
    sidebar.classList.toggle('active');
    overlay.classList.toggle('active');
    
    // Prevent body scroll when mobile menu is open
    if (sidebar.classList.contains('active')) {
      document.body.style.overflow = 'hidden';
    } else {
      document.body.style.overflow = '';
    }
  }
</script>
