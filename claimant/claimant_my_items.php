
<?php 
require_once('../includes/auth_check.php');
requireRole('claimant');

// User info from auth_check
$user = getUserInfo();
$current_page = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>My Lost Items - Lost & Found Dashboard</title>
  <script src="<?php echo htmlspecialchars(finditAssetUrl('vendor/tailwind/tailwind.cdn.js'), ENT_QUOTES, 'UTF-8'); ?>"></script>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(finditAssetUrl('css/unified-ui.css'), ENT_QUOTES, 'UTF-8'); ?>">
  <style>
      .gradient-purple {
          background: linear-gradient(135deg, #ff5b1f 0%, #ff7b2c 100%);
      }
      .status-lost { background: linear-gradient(135deg, #ff6b6b 0%, #ee5a24 100%); }
      .status-found { background: linear-gradient(135deg, #4ecdc4 0%, #44a08d 100%); }
      .status-claimed { background: linear-gradient(135deg, #95a5a6 0%, #7f8c8d 100%); }
      .status-unclaimed { background: linear-gradient(135deg, #f39c12 0%, #e67e22 100%); }
      .status-redistributed { background: linear-gradient(135deg, #9b59b6 0%, #8e44ad 100%); }
      .item-card { transition: all 0.3s ease; }
    .item-card:hover { transform: translateY(-2px); box-shadow: 0 14px 30px rgba(0,0,0,0.32); }
      .status-chip {
          border-radius: 9999px;
          font-size: 0.75rem;
          font-weight: 600;
          padding: 0.2rem 0.6rem;
          display: inline-flex;
          align-items: center;
          gap: 0.3rem;
      }
      .status-chip-lost { background: #fee2e2; color: #991b1b; }
      .status-chip-found { background: #cffafe; color: #155e75; }
      .status-chip-claimed { background: #dcfce7; color: #166534; }
      .status-chip-unclaimed { background: #fef3c7; color: #92400e; }
      .status-chip-redistributed { background: #ede9fe; color: #5b21b6; }
      .ml-sidebar { 
          margin-left: 280px; 
          min-height: 100vh; 
          max-height: 100vh;
          overflow-y: auto; 
          overflow-x: hidden;
          padding: 24px; 
          scroll-behavior: smooth;
          scrollbar-width: thin;
          scrollbar-color: #364152 #121922;
      }
      
      /* Custom scrollbar for webkit browsers */
      .ml-sidebar::-webkit-scrollbar {
          width: 8px;
      }

      .ml-sidebar::-webkit-scrollbar-track {
          background: #121922;
          border-radius: 4px;
      }

      .ml-sidebar::-webkit-scrollbar-thumb {
          background: #364152;
          border-radius: 4px;
          transition: background 0.2s ease;
      }

      .ml-sidebar::-webkit-scrollbar-thumb:hover {
          background: #4b5568;
      }
      
      /* Enhanced modal styles */
      .modal { 
          display: none; 
          position: fixed;
          top: 0;
          left: 0;
          right: 0;
          bottom: 0;
          z-index: 1000;
      }
      .modal.show { 
          display: flex; 
          align-items: center;
          justify-content: center;
      }

      .modal-panel {
          border: 1px solid #273040;
          background: #131922;
          box-shadow: 0 24px 40px rgba(0,0,0,0.46);
      }
      
      /* Modal content scrollable */
      .modal .modal-panel {
          max-height: 90vh;
          overflow-y: auto;
          scrollbar-width: thin;
          scrollbar-color: #364152 #131922;
      }
      
      .modal .modal-panel::-webkit-scrollbar {
          width: 6px;
      }

      .modal .modal-panel::-webkit-scrollbar-track {
          background: #131922;
          border-radius: 3px;
      }

      .modal .modal-panel::-webkit-scrollbar-thumb {
          background: #364152;
          border-radius: 3px;
      }
      
      .loading { opacity: 0.5; pointer-events: none; }
      
      /* Smooth animations for better UX */
      .fade-in {
          animation: fadeInUp 0.6s ease-out;
      }

      @keyframes fadeInUp {
          from {
              opacity: 0;
              transform: translateY(30px);
          }
          to {
              opacity: 1;
              transform: translateY(0);
          }
      }
      
      /* Grid animation */
      .grid-item {
          opacity: 0;
          transform: translateY(20px);
          transition: opacity 0.6s ease, transform 0.6s ease;
      }

      .grid-item.animate {
          opacity: 1;
          transform: translateY(0);
      }

      .action-dropdown-menu {
          z-index: 9999;
      }

      .form-split {
          align-items: stretch;
      }

      .form-pane {
          background: #0f151e;
          border: 1px solid #273040;
          border-radius: 14px;
          padding: 1rem;
          height: 100%;
          display: flex;
          flex-direction: column;
          gap: 0.9rem;
      }

      .form-pane-title {
          color: #ecf2ff;
          font-size: 1rem;
          font-weight: 700;
          letter-spacing: 0.01em;
          display: inline-flex;
          align-items: center;
          gap: 0.45rem;
          padding-bottom: 0.5rem;
          border-bottom: 1px solid #273040;
      }

      #itemForm input[type='text'],
      #itemForm textarea,
      #itemForm select {
          background: #0b1220;
          border: 1px solid #2f3c52;
          color: #ecf2ff;
      }

      #itemForm input[type='text']:focus,
      #itemForm textarea:focus,
      #itemForm select:focus {
          border-color: #ff7b2c;
          box-shadow: 0 0 0 3px rgba(255, 123, 44, 0.2);
      }

      #itemForm label {
          color: #d2deef;
      }

      #itemForm p.text-xs,
      #itemForm p.text-sm {
          color: #95a4bc;
      }

      .guideline-list li {
          display: flex;
          align-items: flex-start;
          gap: 0.45rem;
      }

      .guideline-dot {
          width: 0.35rem;
          height: 0.35rem;
          margin-top: 0.35rem;
          border-radius: 999px;
          background: #ff7b2c;
          flex-shrink: 0;
      }
  </style>
</head>
<body class="font-sans claimant-theme">
<?php include "../includes/components/shared_sidebar.php"; ?>

<!-- Main Content -->
<div class="ml-sidebar">
  <div class="max-w-7xl mx-auto">
    <div class="theme-surface rounded-xl shadow-lg p-8">
      <div class="mb-8">
        <div class="flex items-center justify-between mb-4">
          <div class="flex items-center gap-3">
            <span class="gradient-purple text-white px-4 py-2 rounded-full text-sm font-semibold">My Items</span>
          </div>
          <button onclick="openAddModal()" class="gradient-purple text-white px-6 py-2 rounded-lg font-semibold hover:opacity-90">
            + Add New Item
          </button>
        </div>
        <h1 class="text-3xl font-bold text-gray-900 mb-2">My Lost Items</h1>
        <p class="text-gray-600 text-lg">View and manage all the items you've reported as lost. Track statuses and see potential matches.</p>
      </div>

      <!-- Loading state -->
      <div id="loadingState" class="text-center py-16">
        <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-teal-600 mx-auto mb-4"></div>
        <p class="text-gray-600">Loading your items...</p>
      </div>

            <!-- Items Container -->
                        <div id="itemsContainer" class="hidden border border-gray-200 rounded-xl relative">
        <!-- Items will be loaded dynamically here -->
      </div>

      <!-- Empty State -->
      <div id="emptyState" class="text-center py-16 hidden">
        <div class="mb-6">
          <svg class="w-24 h-24 text-gray-300 mx-auto" fill="currentColor" viewBox="0 0 20 20">
            <path fill-rule="evenodd" d="M3 4a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zM3 8a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zM3 12a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zM3 16a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1z" clip-rule="evenodd"></path>
          </svg>
        </div>
        <h3 class="text-2xl font-semibold text-gray-900 mb-2">No Lost Items Reported Yet</h3>
        <p class="text-gray-600 mb-6 max-w-md mx-auto">Start by reporting a lost item to track it here and find potential matches.</p>
        <div class="space-x-4">
          <button onclick="openAddModal('lost')" class="gradient-purple text-white px-8 py-3 rounded-lg font-semibold hover:opacity-90">Report Lost Item</button>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Add/Edit Item Modal -->
<div id="itemModal" class="modal fixed inset-0 bg-black bg-opacity-50 items-center justify-center z-50">
        <div class="modal-panel theme-modal-panel rounded-lg max-w-4xl w-full mx-4 max-h-screen overflow-y-auto">
    <div class="p-6">
      <div class="flex justify-between items-center mb-6">
                <h2 id="modalTitle" class="text-2xl font-semibold text-gray-100 inline-flex items-center gap-2"><svg class="w-6 h-6 text-orange-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14-6H5m14 12H5m14 6H5"></path></svg>Report Lost Item</h2>
                <button onclick="closeItemModal()" class="text-gray-300 hover:text-white" aria-label="Close item modal">
          <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
          </svg>
        </button>
      </div>
      
      <form id="itemForm" onsubmit="submitItem(event)" enctype="multipart/form-data">
        <input type="hidden" id="itemId" name="item_id">
        
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 form-split">
          <!-- Left Column - Basic Information -->
                    <div class="form-pane">
                        <h3 class="form-pane-title"><svg class="w-4 h-4 text-orange-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>Basic Information</h3>
            
            <div>
              <label for="title" class="block text-sm font-medium text-gray-700 mb-2">
                Item Title <span class="text-red-500">*</span>
              </label>
              <input type="text" id="title" name="title" required 
                     maxlength="50" 
                     class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500">
              <p class="text-xs text-gray-500 mt-1">Maximum 50 characters</p>
            </div>
            
            <div>
              <label for="description" class="block text-sm font-medium text-gray-700 mb-2">
                Description
              </label>
              <textarea id="description" name="description" rows="4" 
                        maxlength="100"
                        placeholder="Describe the item in detail..."
                        class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500 resize-none"></textarea>
              <p class="text-xs text-gray-500 mt-1">Maximum 100 characters</p>
            </div>
            
            <div>
              <label for="category" class="block text-sm font-medium text-gray-700 mb-2">
                Category <span class="text-red-500">*</span>
              </label>
              <select id="category" name="category" required onchange="handleCategoryChange()"
                      class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500">
                <option value="">Select Category</option>
                <option value="Electronics">Electronics</option>
                <option value="Clothing">Clothing & Accessories</option>
                <option value="Books">Books & Documents</option>
                <option value="Bags">Bags & Luggage</option>
                <option value="Jewelry">Jewelry & Watches</option>
                <option value="Sports Equipment">Sports & Recreation</option>
                <option value="Personal Items">Personal Items</option>
                <option value="Keys">Keys & Cards</option>
                <option value="Other">Other (Specify)</option>
              </select>
            </div>

                        <div>
                            <label for="location" class="block text-sm font-medium text-gray-700 mb-2">
                                Last Seen Location <span class="text-red-500">*</span>
                            </label>
                            <input type="text" id="location" name="location" required
                                         maxlength="255"
                                         placeholder="e.g., Main Library 2nd Floor, near Study Hall"
                                         class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500">
                            <p class="text-xs text-gray-500 mt-1">Maximum 255 characters</p>
                        </div>
            
            <!-- Custom Category Input (Hidden by default) -->
            <div id="customCategoryDiv" class="hidden">
              <label for="customCategory" class="block text-sm font-medium text-gray-700 mb-2">
                Specify Category <span class="text-red-500">*</span>
              </label>
              <input type="text" id="customCategory" name="custom_category" 
                     maxlength="20"
                     placeholder="Enter custom category"
                     class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500">
              <p class="text-xs text-gray-500 mt-1">Maximum 20 characters</p>
            </div>
            
            <!-- Status is always 'lost' for claimants -->
            <input type="hidden" id="status" name="status" value="lost">
          </div>
          
          <!-- Right Column - Image Upload -->
                    <div class="form-pane">
                        <h3 class="form-pane-title"><svg class="w-4 h-4 text-orange-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-10h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>Item Image</h3>
            
            <div>
              <label for="item_image" class="block text-sm font-medium text-gray-700 mb-2">
                Upload Image
              </label>
              <div class="border-2 border-dashed border-slate-600 rounded-lg p-6 text-center hover:border-orange-400 transition-colors bg-slate-900/40">
                <input type="file" id="item_image" name="item_image" accept="image/jpeg,image/jpg,image/png,image/gif"
                       class="hidden" onchange="handleImagePreview(event)">
                <div id="uploadArea" onclick="document.getElementById('item_image').click()" class="cursor-pointer">
                                    <svg class="w-12 h-12 text-orange-300 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                  </svg>
                                    <p class="text-gray-200 mb-2 font-medium">Click to upload an image</p>
                                    <p class="text-xs text-gray-400">JPEG, PNG, GIF up to 5MB</p>
                </div>
              </div>
              
              <!-- Image Preview -->
              <div id="imagePreview" class="mt-4 hidden">
                <div class="relative">
                  <img id="previewImg" src="" alt="Preview" class="w-full h-48 object-cover rounded-lg border">
                  <button type="button" onclick="removeImagePreview()" 
                          class="absolute top-2 right-2 bg-red-500 text-white rounded-full p-2 hover:bg-red-600 transition-colors">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                  </button>
                </div>
                                <p class="text-sm text-gray-300 mt-2 text-center">Image preview</p>
              </div>
            </div>
            
            <!-- Upload Guidelines -->
                                                <div class="mt-auto bg-slate-900 border border-slate-700 rounded-lg p-4">
                                                        <h4 class="text-sm font-semibold text-orange-200 mb-2 inline-flex items-center gap-2"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01"></path></svg>Image Guidelines</h4>
                                                        <ul class="text-xs text-slate-300 space-y-1 guideline-list">
                                                                <li><span class="guideline-dot"></span><span>Use clear, high-quality images.</span></li>
                                                                <li><span class="guideline-dot"></span><span>Show the item from multiple angles when possible.</span></li>
                                                                <li><span class="guideline-dot"></span><span>Ensure good lighting for better visibility.</span></li>
                                                                <li><span class="guideline-dot"></span><span>Avoid blurry or heavily cropped photos.</span></li>
                                                                <li><span class="guideline-dot"></span><span>Maximum file size is 5MB.</span></li>
              </ul>
            </div>
          </div>
        </div>
        
        <!-- Form Actions -->
        <div class="flex justify-end space-x-4 mt-8 pt-6 border-t">
          <button type="button" onclick="closeItemModal()" 
                                    class="theme-btn-soft px-6 py-3 rounded-lg font-medium transition-colors inline-flex items-center gap-2">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
            Cancel
          </button>
          <button type="submit" id="submitBtn"
                            class="theme-btn px-6 py-3 rounded-lg font-medium transition-colors inline-flex items-center gap-2">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
            Report Lost Item
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- View Item Modal -->
<div id="viewModal" class="modal fixed inset-0 bg-black bg-opacity-50 items-center justify-center z-50">
        <div class="modal-panel theme-modal-panel rounded-lg max-w-lg w-full mx-4 max-h-screen overflow-y-auto">
    <div class="p-6">
      <div class="flex justify-between items-center mb-4">
                <h2 class="text-xl font-semibold text-gray-100 inline-flex items-center gap-2"><svg class="w-5 h-5 text-orange-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01"></path></svg>Item Details</h2>
                <button onclick="closeViewModal()" class="text-gray-300 hover:text-white" aria-label="Close view item modal">
          <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
          </svg>
        </button>
      </div>
      
      <div id="viewContent">
        <!-- Content will be loaded dynamically -->
      </div>
    </div>
  </div>
</div>

<!-- Matches Modal -->
<div id="matchesModal" class="modal fixed inset-0 bg-black bg-opacity-50 items-center justify-center z-50">
        <div class="modal-panel theme-modal-panel rounded-lg max-w-4xl w-full mx-4 max-h-screen overflow-y-auto">
    <div class="p-6">
      <div class="flex justify-between items-center mb-4">
                <h2 class="text-xl font-semibold text-gray-100 inline-flex items-center gap-2"><svg class="w-5 h-5 text-orange-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"></path></svg>Potential Matches</h2>
                <button onclick="closeMatchesModal()" class="text-gray-300 hover:text-white" aria-label="Close matches modal">
          <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
          </svg>
        </button>
      </div>
      
      <div id="matchesContent">
        <!-- Matches will be loaded dynamically -->
      </div>
    </div>
  </div>
</div>

<!-- Delete Confirmation Modal -->
<div id="deleteModal" class="modal fixed inset-0 bg-black bg-opacity-50 items-center justify-center z-50">
        <div class="modal-panel theme-modal-panel rounded-lg max-w-sm w-full mx-4">
    <div class="p-6">
      <div class="mb-4">
                <h3 class="text-lg font-semibold text-gray-100 mb-2 inline-flex items-center gap-2"><svg class="w-5 h-5 text-red-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"></path></svg>Confirm Delete</h3>
        <p class="text-gray-600">Are you sure you want to delete this item? This action cannot be undone.</p>
      </div>
      
      <div class="flex justify-end space-x-3">
        <button onclick="closeDeleteModal()" 
                                class="theme-btn-soft px-4 py-2 rounded-md inline-flex items-center gap-2">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
          Cancel
        </button>
        <button onclick="confirmDelete()" id="deleteBtn"
                                class="theme-btn-danger px-4 py-2 rounded-md inline-flex items-center gap-2">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6M9 7V4a1 1 0 011-1h4a1 1 0 011 1v3M4 7h16"></path></svg>
          Delete
        </button>
      </div>
    </div>
  </div>
</div>

<script>
// Global variables
let currentItemId = null;
let deleteItemId = null;
const ITEMS_PER_PAGE = 5;
let allItems = [];
let currentItemsPage = 1;

// Load items when page loads
document.addEventListener('DOMContentLoaded', function() {
    loadUserItems();
    
    // Add image preview functionality
    const imageInput = document.getElementById('item_image');
    if (imageInput) {
        imageInput.addEventListener('change', handleImagePreview);
    }
    
    // Add character counters
    addCharacterCounters();
    
    // Initialize scroll features
    initScrollFeatures();
    
    // Add back to top button
    addBackToTopButton();
});

// Initialize scroll features
function initScrollFeatures() {
    const mainContent = document.querySelector('.ml-sidebar');
    
    // Add scroll progress indicator
    const progressBar = document.createElement('div');
    progressBar.style.cssText = `
        position: fixed;
        top: 0;
        left: 280px;
        right: 0;
        height: 3px;
        background: linear-gradient(135deg, #ff5b1f, #ff7b2c);
        transform-origin: left;
        transform: scaleX(0);
        transition: transform 0.1s ease;
        z-index: 999;
    `;
    document.body.appendChild(progressBar);

    // Update progress bar on scroll
    mainContent.addEventListener('scroll', function() {
        const scrollTop = this.scrollTop;
        const scrollHeight = this.scrollHeight - this.clientHeight;
        const scrollPercent = scrollHeight > 0 ? scrollTop / scrollHeight : 0;
        progressBar.style.transform = `scaleX(${scrollPercent})`;
    });
}

// Add back to top button
function addBackToTopButton() {
    const backToTop = document.createElement('button');
    backToTop.innerHTML = '&uarr;';
    backToTop.style.cssText = `
        position: fixed;
        bottom: 2rem;
        right: 2rem;
        width: 50px;
        height: 50px;
        border-radius: 50%;
        background: linear-gradient(135deg, #ff5b1f, #ff7b2c);
        color: white;
        border: none;
        font-size: 20px;
        cursor: pointer;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        opacity: 0;
        transform: scale(0);
        transition: all 0.3s ease;
        z-index: 1000;
    `;
    
    backToTop.addEventListener('click', function() {
        document.querySelector('.ml-sidebar').scrollTo({
            top: 0,
            behavior: 'smooth'
        });
    });

    document.body.appendChild(backToTop);

    // Show/hide back to top button
    document.querySelector('.ml-sidebar').addEventListener('scroll', function() {
        if (this.scrollTop > 300) {
            backToTop.style.opacity = '1';
            backToTop.style.transform = 'scale(1)';
        } else {
            backToTop.style.opacity = '0';
            backToTop.style.transform = 'scale(0)';
        }
    });
}

// Add character counters for text inputs
function addCharacterCounters() {
    const titleInput = document.getElementById('title');
    const descriptionInput = document.getElementById('description');
    const customCategoryInput = document.getElementById('customCategory');
    const locationInput = document.getElementById('location');
    
    if (titleInput) {
        titleInput.addEventListener('input', function() {
            updateCharacterCount(this, 50);
        });
    }
    
    if (descriptionInput) {
        descriptionInput.addEventListener('input', function() {
            updateCharacterCount(this, 100);
        });
    }
    
    if (customCategoryInput) {
        customCategoryInput.addEventListener('input', function() {
            updateCharacterCount(this, 20);
        });
    }

    if (locationInput) {
        locationInput.addEventListener('input', function() {
            updateCharacterCount(this, 255);
        });
    }
}

// Update character count display
function updateCharacterCount(input, maxLength) {
    const currentLength = input.value.length;
    const remaining = maxLength - currentLength;
    
    // Find or create the counter element
    let counter = input.parentNode.querySelector('.char-counter');
    if (!counter) {
        counter = document.createElement('p');
        counter.className = 'char-counter text-xs mt-1';
        input.parentNode.appendChild(counter);
    }
    
    // Update counter text and color
    if (remaining < 0) {
        counter.textContent = `${Math.abs(remaining)} characters over limit`;
        counter.className = 'char-counter text-xs mt-1 text-red-500';
        input.classList.add('border-red-500');
    } else if (remaining <= 10) {
        counter.textContent = `${remaining} characters remaining`;
        counter.className = 'char-counter text-xs mt-1 text-yellow-600';
        input.classList.remove('border-red-500');
    } else {
        counter.textContent = `${remaining} characters remaining`;
        counter.className = 'char-counter text-xs mt-1 text-gray-500';
        input.classList.remove('border-red-500');
    }
}

// Handle image preview
function handleImagePreview(event) {
    const file = event.target.files[0];
    const previewContainer = document.getElementById('imagePreview');
    const previewImg = document.getElementById('previewImg');
    const uploadArea = document.getElementById('uploadArea');
    
    if (file) {
        // Validate file type
        if (!file.type.startsWith('image/')) {
            showError('Please select an image file');
            event.target.value = '';
            return;
        }
        
        // Validate file size (5MB)
        if (file.size > 5 * 1024 * 1024) {
            showError('File size must be less than 5MB');
            event.target.value = '';
            return;
        }
        
        const reader = new FileReader();
        reader.onload = function(e) {
            previewImg.src = e.target.result;
            previewContainer.classList.remove('hidden');
            uploadArea.classList.add('hidden');
        };
        reader.readAsDataURL(file);
    } else {
        previewContainer.classList.add('hidden');
        uploadArea.classList.remove('hidden');
    }
}

// Handle category dropdown change
function handleCategoryChange() {
    const categorySelect = document.getElementById('category');
    const customCategoryDiv = document.getElementById('customCategoryDiv');
    const customCategoryInput = document.getElementById('customCategory');
    
    if (categorySelect.value === 'Other') {
        customCategoryDiv.classList.remove('hidden');
        customCategoryInput.required = true;
        customCategoryInput.focus();
    } else {
        customCategoryDiv.classList.add('hidden');
        customCategoryInput.required = false;
        customCategoryInput.value = '';
    }
}

// Remove image preview
function removeImagePreview() {
    document.getElementById('item_image').value = '';
    document.getElementById('imagePreview').classList.add('hidden');
    document.getElementById('uploadArea').classList.remove('hidden');
    
    // Remove any existing image note
    const existingNote = document.getElementById('existing-image-note');
    if (existingNote) existingNote.remove();
}

// Load user items from API
async function loadUserItems() {
    try {
        console.log('Loading user items...');
        const response = await fetch('api/items_api.php?action=get_user_items');
        
        // Check if the response is ok
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}: ${response.statusText}`);
        }
        
        const rawText = await response.text(); // Get as text first to debug
        console.log('Items API raw response:', rawText);
        
        // Check if response is empty
        if (!rawText.trim()) {
            throw new Error('Empty response from server');
        }
        
        let data;
        try {
            data = JSON.parse(rawText);
        } catch (parseError) {
            console.error('Items API JSON parse error:', parseError);
            console.error('Raw response was:', rawText);
            
            // Check if response contains HTML (error page)
            if (rawText.includes('<html>') || rawText.includes('<!DOCTYPE')) {
                showError('Server returned an error page instead of JSON. Check server logs.');
            } else {
                showError('Invalid JSON response from server. Raw response: ' + rawText.substring(0, 100) + '...');
            }
            return;
        }
        
        if (data && data.success) {
            allItems = Array.isArray(data.items) ? data.items : [];
            currentItemsPage = 1;
            displayItems(allItems);
        } else {
            showError('Failed to load items: ' + (data.error || 'Unknown error'));
        }
    } catch (error) {
        console.error('Network error:', error);
        showError('Network error loading items: ' + error.message);
    }
}

// Display items in the UI
function displayItems(items) {
    const loadingState = document.getElementById('loadingState');
    const itemsContainer = document.getElementById('itemsContainer');
    const emptyState = document.getElementById('emptyState');
    
    loadingState.classList.add('hidden');
    
    if (items.length === 0) {
        emptyState.classList.remove('hidden');
        itemsContainer.classList.add('hidden');
        return;
    }
    
    emptyState.classList.add('hidden');
    itemsContainer.classList.remove('hidden');

    const totalPages = Math.max(1, Math.ceil(items.length / ITEMS_PER_PAGE));
    currentItemsPage = Math.min(Math.max(currentItemsPage, 1), totalPages);
    const startIndex = (currentItemsPage - 1) * ITEMS_PER_PAGE;
    const pagedItems = items.slice(startIndex, startIndex + ITEMS_PER_PAGE);
    
    const statusClassMap = {
        lost: 'status-chip status-chip-lost',
        found: 'status-chip status-chip-found',
        claimed: 'status-chip status-chip-claimed',
        unclaimed: 'status-chip status-chip-unclaimed',
        redistributed: 'status-chip status-chip-redistributed'
    };

    itemsContainer.innerHTML = `
        <table class="min-w-full divide-y divide-gray-200 text-sm bg-white">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left font-semibold text-gray-700">Item</th>
                    <th class="px-4 py-3 text-left font-semibold text-gray-700">Category</th>
                    <th class="px-4 py-3 text-left font-semibold text-gray-700">Location</th>
                    <th class="px-4 py-3 text-left font-semibold text-gray-700">Status</th>
                    <th class="px-4 py-3 text-left font-semibold text-gray-700">Reported</th>
                    <th class="px-4 py-3 text-left font-semibold text-gray-700">Matches</th>
                    <th class="px-4 py-3 text-left font-semibold text-gray-700">Best Match</th>
                    <th class="px-4 py-3 text-left font-semibold text-gray-700">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                ${pagedItems.map(item => `
                    <tr class="hover:bg-gray-50 transition-colors">
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-3">
                                <img src="${escapeHtml(item.item_image || 'https://via.placeholder.com/60?text=No+Image')}" alt="${escapeHtml(item.title)}" class="w-12 h-12 object-cover rounded-md" onerror="this.onerror=null; this.src='https://via.placeholder.com/60?text=No+Image';">
                                <div>
                                    <p class="font-semibold text-gray-900">${escapeHtml(item.title)}</p>
                                    <p class="text-xs text-gray-500">${escapeHtml(item.description || 'No description provided').slice(0, 60)}</p>
                                </div>
                            </div>
                        </td>
                        <td class="px-4 py-3 text-gray-700">${escapeHtml(item.category)}</td>
                        <td class="px-4 py-3 text-gray-700">${escapeHtml(item.location || 'Not provided')}</td>
                        <td class="px-4 py-3"><span class="${statusClassMap[item.status] || 'status-chip status-chip-lost'}">${escapeHtml(item.status)}</span></td>
                        <td class="px-4 py-3 text-gray-700">${item.report_date}</td>
                        <td class="px-4 py-3 text-gray-700 font-medium">${item.match_count}</td>
                        <td class="px-4 py-3 text-gray-700">${item.best_match_score > 0 ? `${item.best_match_score}%` : '-'}</td>
                        <td class="px-4 py-3">
                            <div class="relative inline-block text-left action-dropdown" data-item-id="${item.item_id}">
                                <button type="button" onclick="toggleActionDropdown(${item.item_id})" class="inline-flex items-center gap-1.5 bg-gradient-to-r from-slate-800 to-slate-900 hover:from-slate-900 hover:to-black text-white px-3 py-1.5 rounded-lg text-xs font-medium transition-all shadow-sm">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 5v.01M12 12v.01M12 19v.01"></path>
                                    </svg>
                                    Actions
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                                    </svg>
                                </button>
                                <div id="action-dropdown-${item.item_id}" class="action-dropdown-menu hidden absolute right-0 mt-2 w-44 bg-white border border-gray-200 rounded-lg shadow-lg py-1">
                                    <button type="button" onclick="viewItem(${item.item_id}); closeAllActionDropdowns();" class="w-full text-left px-3 py-2 text-xs text-gray-700 hover:bg-gray-100 inline-flex items-center gap-2">
                                        <svg class="w-4 h-4 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5s8.268 2.943 9.542 7c-1.274 4.057-5.064 7-9.542 7s-8.268-2.943-9.542-7z"></path></svg>
                                        View Details
                                    </button>
                                    ${item.match_count > 0
                                        ? `<button type="button" onclick="viewMatches(${item.item_id}); closeAllActionDropdowns();" class="w-full text-left px-3 py-2 text-xs text-gray-700 hover:bg-gray-100 inline-flex items-center gap-2"><svg class="w-4 h-4 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"></path></svg>View Matches (${item.match_count})</button>`
                                        : `<button type="button" onclick="editItem(${item.item_id}); closeAllActionDropdowns();" class="w-full text-left px-3 py-2 text-xs text-gray-700 hover:bg-gray-100 inline-flex items-center gap-2"><svg class="w-4 h-4 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path></svg>Edit Item</button>`}
                                    <button type="button" onclick="deleteItem(${item.item_id}); closeAllActionDropdowns();" class="w-full text-left px-3 py-2 text-xs text-red-600 hover:bg-red-50 inline-flex items-center gap-2"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6M9 7V4a1 1 0 011-1h4a1 1 0 011 1v3M4 7h16"></path></svg>Delete Item</button>
                                </div>
                            </div>
                        </td>
                    </tr>
                `).join('')}
            </tbody>
        </table>
        ${totalPages > 1 ? `
            <div class="flex items-center justify-between px-4 py-3 border-t border-gray-200 bg-white">
                <p class="text-xs text-gray-600">Showing ${startIndex + 1}-${Math.min(startIndex + ITEMS_PER_PAGE, items.length)} of ${items.length} items</p>
                <div class="flex items-center gap-2">
                    <button type="button" onclick="changeItemsPage(${currentItemsPage - 1})" class="px-3 py-1.5 text-xs rounded-md border border-gray-300 bg-white text-gray-700 hover:bg-gray-50 ${currentItemsPage === 1 ? 'opacity-50 cursor-not-allowed' : ''}" ${currentItemsPage === 1 ? 'disabled' : ''}>Previous</button>
                    <span class="text-xs text-gray-600">Page ${currentItemsPage} of ${totalPages}</span>
                    <button type="button" onclick="changeItemsPage(${currentItemsPage + 1})" class="px-3 py-1.5 text-xs rounded-md border border-gray-300 bg-white text-gray-700 hover:bg-gray-50 ${currentItemsPage === totalPages ? 'opacity-50 cursor-not-allowed' : ''}" ${currentItemsPage === totalPages ? 'disabled' : ''}>Next</button>
                </div>
            </div>
        ` : ''}
    `;
}

function changeItemsPage(page) {
    const totalPages = Math.max(1, Math.ceil(allItems.length / ITEMS_PER_PAGE));
    const nextPage = Math.min(Math.max(page, 1), totalPages);

    if (nextPage === currentItemsPage) {
        return;
    }

    currentItemsPage = nextPage;
    closeAllActionDropdowns();
    displayItems(allItems);
}

function closeAllActionDropdowns() {
    document.querySelectorAll('[id^="action-dropdown-"]').forEach(menu => {
        menu.classList.add('hidden');
    });
}

function toggleActionDropdown(itemId) {
    const targetMenu = document.getElementById(`action-dropdown-${itemId}`);
    if (!targetMenu) return;

    const willOpen = targetMenu.classList.contains('hidden');
    closeAllActionDropdowns();

    if (willOpen) {
        targetMenu.classList.remove('hidden');
    }
}

// Modal functions
function openAddModal(status = 'lost') {
    document.getElementById('modalTitle').textContent = 'Report Lost Item';
    document.getElementById('submitBtn').textContent = 'Report Lost Item';
    document.getElementById('itemForm').reset();
    document.getElementById('itemId').value = '';
    document.getElementById('imagePreview').classList.add('hidden');
    document.getElementById('uploadArea').classList.remove('hidden');
    document.getElementById('customCategoryDiv').classList.add('hidden');
    document.getElementById('customCategory').required = false;
    
    // Remove any existing image note
    const existingNote = document.getElementById('existing-image-note');
    if (existingNote) existingNote.remove();
    
    // Always set status to 'lost' for claimants
    document.getElementById('status').value = 'lost';
    document.getElementById('itemModal').classList.add('show');
}

function closeItemModal() {
    document.getElementById('itemModal').classList.remove('show');
    document.getElementById('imagePreview').classList.add('hidden');
    document.getElementById('uploadArea').classList.remove('hidden');
    document.getElementById('customCategoryDiv').classList.add('hidden');
    document.getElementById('customCategory').required = false;
    
    // Remove any existing image note
    const existingNote = document.getElementById('existing-image-note');
    if (existingNote) existingNote.remove();
}

function editItem(itemId) {
    currentItemId = itemId;
    document.getElementById('modalTitle').textContent = 'Edit Lost Item';
    document.getElementById('submitBtn').textContent = 'Update Lost Item';
    
    // Load item data
    fetch(`api/items_api.php?action=get_item&id=${itemId}`)
        .then(response => response.text())
        .then(rawText => {
            console.log('Edit item raw response:', rawText);
            let data;
            try {
                data = JSON.parse(rawText);
            } catch (parseError) {
                console.error('Edit item JSON parse error:', parseError);
                showError('Failed to parse server response');
                return;
            }
            
            if (data.success) {
                const item = data.item;
                document.getElementById('itemId').value = item.item_id;
                document.getElementById('title').value = item.title;
                document.getElementById('description').value = item.description || '';
                document.getElementById('location').value = item.location || '';
                // Always set status to 'lost' for claimants
                document.getElementById('status').value = 'lost';
                
                // Handle category - check if it's a predefined category or custom
                const categorySelect = document.getElementById('category');
                const customCategoryDiv = document.getElementById('customCategoryDiv');
                const customCategoryInput = document.getElementById('customCategory');
                
                // List of predefined categories
                const predefinedCategories = ['Electronics', 'Clothing', 'Books', 'Accessories', 'Documents', 'Bags', 'Jewelry', 'Sports Equipment', 'Personal Items', 'Keys'];
                
                if (predefinedCategories.includes(item.category)) {
                    categorySelect.value = item.category;
                    customCategoryDiv.classList.add('hidden');
                    customCategoryInput.required = false;
                } else {
                    categorySelect.value = 'Other';
                    customCategoryDiv.classList.remove('hidden');
                    customCategoryInput.value = item.category;
                    customCategoryInput.required = true;
                }
                
                // Handle existing image display
                const imagePreview = document.getElementById('imagePreview');
                const previewImg = document.getElementById('previewImg');
                const uploadArea = document.getElementById('uploadArea');
                
                // Clear the file input (can't set file input values)
                document.getElementById('item_image').value = '';
                
                // Show current image if exists
                if (item.item_image && item.item_image !== 'https://via.placeholder.com/400x300?text=No+Image') {
                    previewImg.src = item.item_image;
                    imagePreview.classList.remove('hidden');
                    uploadArea.classList.add('hidden');
                    
                    // Add a note about current image
                    const existingImageNote = document.createElement('div');
                    existingImageNote.id = 'existing-image-note';
                    existingImageNote.className = 'bg-slate-900 border border-slate-700 rounded-lg p-3 mt-4';
                    existingImageNote.innerHTML = `
                        <p class="text-sm text-orange-200 font-medium inline-flex items-center gap-2"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-10h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>Current Image</p>
                        <p class="text-xs text-slate-300 mt-1">Upload a new image to replace the current one, or leave this blank to keep the existing image.</p>
                    `;
                    
                    // Remove any existing note
                    const oldNote = document.getElementById('existing-image-note');
                    if (oldNote) oldNote.remove();
                    
                    // Add note after the image preview
                    imagePreview.parentNode.appendChild(existingImageNote);
                } else {
                    imagePreview.classList.add('hidden');
                    uploadArea.classList.remove('hidden');
                    
                    // Remove any existing note
                    const oldNote = document.getElementById('existing-image-note');
                    if (oldNote) oldNote.remove();
                }
                
                document.getElementById('itemModal').classList.add('show');
            } else {
                showError('Failed to load item details: ' + (data.error || 'Unknown error'));
            }
        })
        .catch(error => {
            console.error('Edit item network error:', error);
            showError('Error loading item: ' + error.message);
        });
}

function viewItem(itemId) {
    fetch(`api/items_api.php?action=get_item&id=${itemId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const item = data.item;
                document.getElementById('viewContent').innerHTML = `
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                        <div class="space-y-3">
                            <div class="rounded-xl overflow-hidden border border-gray-200">
                                <img src="${item.item_image || 'https://via.placeholder.com/400x300?text=No+Image'}" alt="${item.title}" class="w-full h-64 object-cover">
                            </div>
                            <div class="bg-gray-50 border border-gray-200 rounded-lg p-3 text-sm text-gray-700">
                                <p class="inline-flex items-center gap-2 font-semibold text-gray-900 mb-1">
                                    <svg class="w-4 h-4 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01"/></svg>
                                    Description
                                </p>
                                <p>${item.description || 'No description provided'}</p>
                            </div>
                        </div>
                        <div class="space-y-3">
                            <div class="bg-white border border-gray-200 rounded-lg p-4">
                                <h4 class="text-sm font-semibold text-gray-900 inline-flex items-center gap-2 mb-2">
                                    <svg class="w-4 h-4 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                    Item Details
                                </h4>
                                <div class="space-y-2 text-sm">
                                    <p><span class="font-medium text-gray-700">Title:</span> <span class="text-gray-900">${item.title}</span></p>
                                    <p><span class="font-medium text-gray-700">Category:</span> <span class="text-gray-900">${item.category}</span></p>
                                    <p><span class="font-medium text-gray-700">Location:</span> <span class="text-gray-900">${item.location || 'Not provided'}</span></p>
                                    <p><span class="font-medium text-gray-700">Status:</span> <span class="capitalize text-gray-900">${item.status}</span></p>
                                    <p><span class="font-medium text-gray-700">Reported Date:</span> <span class="text-gray-900">${new Date(item.report_date).toLocaleDateString()}</span></p>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="mt-6 flex space-x-3">
                        <button onclick="editItem(${item.item_id}); closeViewModal();" 
                                class="flex-1 theme-btn py-2 px-4 rounded-lg font-medium inline-flex items-center justify-center gap-2">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path></svg>
                            Edit Item
                        </button>
                        <button onclick="deleteItem(${item.item_id}); closeViewModal();" 
                                class="flex-1 theme-btn-danger py-2 px-4 rounded-lg font-medium inline-flex items-center justify-center gap-2">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6M9 7V4a1 1 0 011-1h4a1 1 0 011 1v3M4 7h16"></path></svg>
                            Delete Item
                        </button>
                    </div>
                `;
                document.getElementById('viewModal').classList.add('show');
            }
        })
        .catch(error => showError('Error loading item details'));
}

function closeViewModal() {
    document.getElementById('viewModal').classList.remove('show');
}

function viewMatches(itemId) {
    fetch(`api/items_api.php?action=get_matches&id=${itemId}`)
        .then(response => response.text())
        .then(rawText => {
            console.log('View matches raw response:', rawText);
            let data;
            try {
                data = JSON.parse(rawText);
            } catch (parseError) {
                console.error('View matches JSON parse error:', parseError);
                console.error('Raw response was:', rawText);
                showError('Failed to parse server response for matches');
                return;
            }
            
            if (data.success) {
                const matches = data.matches;
                if (matches.length === 0) {
                    document.getElementById('matchesContent').innerHTML = `
                        <div class="text-center py-8">
                            <svg class="w-16 h-16 text-gray-300 mx-auto mb-4" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm-1-4a1 1 0 001 1h.01a1 1 0 001-1v-.01a1 1 0 00-1-1H10a1 1 0 00-1 1V14zm0-3V5a1 1 0 112 0v6a1 1 0 11-2 0z" clip-rule="evenodd" />
                            </svg>
                            <h3 class="text-lg font-medium text-gray-900 mb-2">No Matches Yet</h3>
                            <p class="text-gray-600">The smart matching system is still looking for potential matches.</p>
                            <p class="text-sm text-gray-500 mt-2">New matches will appear here automatically as more items are reported.</p>
                        </div>
                    `;
                } else {
                    document.getElementById('matchesContent').innerHTML = `
                        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-4">
                            <div class="p-4 bg-blue-50 border border-blue-200 rounded-lg">
                                <div class="flex items-start">
                                    <svg class="h-5 w-5 text-blue-400 mr-2 mt-0.5" viewBox="0 0 20 20" fill="currentColor">
                                        <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd" />
                                    </svg>
                                    <div>
                                        <h4 class="text-sm font-semibold text-blue-800">Smart Matching Results</h4>
                                        <p class="text-xs text-blue-700 mt-1">Found ${matches.length} potential match${matches.length > 1 ? 'es' : ''}. Review and open details or chat.</p>
                                    </div>
                                </div>
                            </div>
                            <div class="p-4 bg-gray-50 border border-gray-200 rounded-lg text-xs text-gray-700">
                                <h5 class="font-semibold text-gray-800 mb-2 inline-flex items-center gap-1">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01"/></svg>
                                    How It Works
                                </h5>
                                <p>AI compares title, description, category, and location. Higher score means stronger similarity.</p>
                            </div>
                        </div>
                        <div class="overflow-x-auto border border-gray-200 rounded-lg">
                            <table class="min-w-full divide-y divide-gray-200 text-sm bg-white">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-4 py-3 text-left font-semibold text-gray-700">Match Item</th>
                                        <th class="px-4 py-3 text-left font-semibold text-gray-700">Score</th>
                                        <th class="px-4 py-3 text-left font-semibold text-gray-700">Contact</th>
                                        <th class="px-4 py-3 text-left font-semibold text-gray-700">Category</th>
                                        <th class="px-4 py-3 text-left font-semibold text-gray-700">Location</th>
                                        <th class="px-4 py-3 text-left font-semibold text-gray-700">Reported</th>
                                        <th class="px-4 py-3 text-left font-semibold text-gray-700">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    ${matches.map(match => `
                                        <tr class="hover:bg-gray-50">
                                            <td class="px-4 py-3">
                                                <div class="flex items-center gap-3">
                                                    <img src="${match.item_image || 'https://via.placeholder.com/56?text=No+Image'}" alt="${escapeHtml(match.title)}" class="w-12 h-12 object-cover rounded-md" onerror="this.onerror=null; this.src='https://via.placeholder.com/56?text=No+Image';">
                                                    <div>
                                                        <p class="font-medium text-gray-900">${escapeHtml(match.title)}</p>
                                                        <p class="text-xs text-gray-500">${escapeHtml(match.description || 'No description').slice(0, 48)}</p>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="px-4 py-3 font-semibold text-green-700">${Math.round(match.similarity_score)}%</td>
                                            <td class="px-4 py-3 text-gray-700">${escapeHtml(match.finder_name || match.owner_name)}</td>
                                            <td class="px-4 py-3 text-gray-700">${escapeHtml(match.category || 'N/A')}</td>
                                            <td class="px-4 py-3 text-gray-700">${escapeHtml(match.location || 'Not provided')}</td>
                                            <td class="px-4 py-3 text-gray-700">${match.report_date}</td>
                                            <td class="px-4 py-3">
                                                <div class="flex flex-wrap gap-2">
                                                    ${match.match_id ? `<a href="claimant_matches_chats.php?match_id=${match.match_id}" class="theme-btn py-1.5 px-3 rounded-md text-xs font-medium inline-flex items-center gap-1"><svg class=\"w-3.5 h-3.5\" fill=\"none\" stroke=\"currentColor\" viewBox=\"0 0 24 24\"><path stroke-linecap=\"round\" stroke-linejoin=\"round\" stroke-width=\"2\" d=\"M8 10h8m-8 4h5m7-2a9 9 0 11-18 0 9 9 0 0118 0z\"></path></svg>Chat</a>` : ''}
                                                    <button onclick="viewMatchDetails(${match.item_id || match.match_item_id})" class="theme-btn-soft py-1.5 px-3 rounded-md text-xs font-medium inline-flex items-center gap-1"><svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01"></path></svg>Details</button>
                                                </div>
                                            </td>
                                        </tr>
                                    `).join('')}
                                </tbody>
                            </table>
                        </div>
                    `;
                }
                document.getElementById('matchesModal').classList.add('show');
            } else {
                showError('Failed to load matches: ' + (data.error || 'Unknown error'));
            }
        })
        .catch(error => {
            console.error('View matches network error:', error);
            showError('Error loading matches: ' + error.message);
        });
}

function closeMatchesModal() {
    document.getElementById('matchesModal').classList.remove('show');
}

function viewMatchDetails(itemId) {
    // Close matches modal
    closeMatchesModal();
    
    // Open a simple view for the matched item
    window.open(`claimant_all_items.php?highlight=${itemId}`, '_blank');
}

function deleteItem(itemId) {
    deleteItemId = itemId;
    document.getElementById('deleteModal').classList.add('show');
}

function closeDeleteModal() {
    document.getElementById('deleteModal').classList.remove('show');
    deleteItemId = null;
}

function confirmDelete() {
    if (!deleteItemId) return;
    
    const deleteBtn = document.getElementById('deleteBtn');
    deleteBtn.textContent = 'Deleting...';
    deleteBtn.disabled = true;
    
    fetch(`api/items_api.php?action=delete_item&id=${deleteItemId}`, {
        method: 'DELETE'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showSuccess('Item deleted successfully');
            closeDeleteModal();
            loadUserItems(); // Reload the items
        } else {
            showError('Failed to delete item: ' + (data.error || 'Unknown error'));
        }
    })
    .catch(error => {
        showError('Error deleting item: ' + error.message);
    })
    .finally(() => {
        deleteBtn.textContent = 'Delete';
        deleteBtn.disabled = false;
    });
}

// Submit item form
function submitItem(event) {
    event.preventDefault();
    
    const form = event.target;
    const formData = new FormData(form);
    const itemId = formData.get('item_id');
    
    // Handle custom category
    const categorySelect = document.getElementById('category');
    const customCategory = document.getElementById('customCategory').value.trim();
    
    if (categorySelect.value === 'Other') {
        if (!customCategory) {
            showError('Please specify the custom category');
            return;
        }
        // Replace the category with the custom value
        formData.set('category', customCategory);
    }
    
    // Validate title length
    const title = formData.get('title').trim();
    if (title.length > 50) {
        showError('Title must be 50 characters or less');
        return;
    }
    
    // Validate description length
    const description = formData.get('description').trim();
    if (description.length > 100) {
        showError('Description must be 100 characters or less');
        return;
    }

    // Validate location
    const location = (formData.get('location') || '').trim();
    if (!location) {
        showError('Location is required');
        return;
    }
    if (location.length > 255) {
        showError('Location must be 255 characters or less');
        return;
    }
    
    const submitBtn = document.getElementById('submitBtn');
    const originalText = submitBtn.textContent;
    submitBtn.textContent = itemId ? 'Updating...' : 'Reporting...';
    submitBtn.disabled = true;
    
    const url = itemId ? `api/items_api.php?action=update_item&id=${itemId}` : 'api/items_api.php?action=add_item';
    
    fetch(url, {
        method: 'POST', // Always use POST for form data with files
        body: formData // Send FormData directly, don't set Content-Type header
    })
    .then(response => response.text()) // Get as text first to debug
    .then(rawText => {
        console.log('Submit item raw response:', rawText);
        let data;
        try {
            data = JSON.parse(rawText);
        } catch (parseError) {
            console.error('Submit item JSON parse error:', parseError);
            console.error('Raw response was:', rawText);
            showError('Failed to parse server response. Check console for details.');
            return;
        }
        
        if (data.success) {
            showSuccess(itemId ? 'Lost item updated successfully' : 'Lost item reported successfully');
            closeItemModal();
            loadUserItems(); // Reload the items
        } else {
            showError('Failed to save item: ' + (data.error || 'Unknown error'));
        }
    })
    .catch(error => {
        console.error('Submit item network error:', error);
        showError('Error saving item: ' + error.message);
    })
    .finally(() => {
        submitBtn.textContent = originalText;
        submitBtn.disabled = false;
    });
}

// Utility functions
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function showError(message) {
    console.error('Error:', message);
    
    // Create a more visible error display
    const errorDiv = document.createElement('div');
    errorDiv.className = 'fixed top-4 right-4 bg-red-500 text-white p-4 rounded-lg shadow-lg z-50 max-w-md';
    errorDiv.innerHTML = `
        <div class="flex justify-between items-start">
            <div>
                <h4 class="font-semibold">Error</h4>
                <p class="text-sm mt-1">${message}</p>
            </div>
            <button onclick="this.parentElement.parentElement.remove()" class="text-white hover:text-gray-200 ml-4">
                <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"></path>
                </svg>
            </button>
        </div>
    `;
    document.body.appendChild(errorDiv);
    
    // Auto remove after 10 seconds
    setTimeout(() => {
        if (errorDiv.parentElement) {
            errorDiv.remove();
        }
    }, 10000);
}

function showSuccess(message) {
    console.log('Success:', message);
    
    // Create a more visible success display
    const successDiv = document.createElement('div');
    successDiv.className = 'fixed top-4 right-4 bg-green-500 text-white p-4 rounded-lg shadow-lg z-50 max-w-md';
    successDiv.innerHTML = `
        <div class="flex justify-between items-start">
            <div>
                <h4 class="font-semibold">Success</h4>
                <p class="text-sm mt-1">${message}</p>
            </div>
            <button onclick="this.parentElement.parentElement.remove()" class="text-white hover:text-gray-200 ml-4">
                <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"></path>
                </svg>
            </button>
        </div>
    `;
    document.body.appendChild(successDiv);
    
    // Auto remove after 5 seconds
    setTimeout(() => {
        if (successDiv.parentElement) {
            successDiv.remove();
        }
    }, 5000);
}

// Close modals when clicking outside
document.addEventListener('click', function(event) {
    if (!event.target.closest('.action-dropdown')) {
        closeAllActionDropdowns();
    }

    const modals = ['itemModal', 'viewModal', 'matchesModal', 'deleteModal'];
    modals.forEach(modalId => {
        const modal = document.getElementById(modalId);
        if (event.target === modal) {
            modal.classList.remove('show');
        }
    });
});
</script>

</body>
</html>

