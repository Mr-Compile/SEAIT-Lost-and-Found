<?php 
require_once '../includes/auth_check.php';
require_once '../config/db.php';
requireRole('claimant');

$user = getUserInfo();
$current_page = basename($_SERVER['PHP_SELF']);
$user_id = $user['user_id'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Claim Requests - FindIt</title>
    <script src="<?php echo htmlspecialchars(finditAssetUrl('vendor/tailwind/tailwind.cdn.js'), ENT_QUOTES, 'UTF-8'); ?>"></script>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(finditAssetUrl('css/unified-ui.css'), ENT_QUOTES, 'UTF-8'); ?>">
    <style>
        :root {
            --surface: #131922;
            --line: #273040;
            --text: #ecf2ff;
            --muted: #95a4bc;
            --brand: #ff5b1f;
            --brand-2: #ff7b2c;
        }

        body {
            margin: 0;
            color: var(--text);
            font-family: 'Manrope', 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background:
                radial-gradient(circle at 8% 8%, rgba(255, 122, 28, 0.16), transparent 38%),
                radial-gradient(circle at 92% 4%, rgba(34, 211, 238, 0.12), transparent 42%),
                #0a0f14;
        }
        
        .main-content {
            margin-left: 280px;
            min-height: 100vh;
            max-height: 100vh;
            overflow-y: auto;
            padding: 24px;
            scroll-behavior: smooth;
            scrollbar-width: thin;
            scrollbar-color: #cbd5e0 #f7fafc;
        }
        
        .main-content::-webkit-scrollbar {
            width: 8px;
        }
        
        .main-content::-webkit-scrollbar-track {
            background: #f7fafc;
        }
        
        .main-content::-webkit-scrollbar-thumb {
            background: #cbd5e0;
            border-radius: 4px;
        }
        
        .status-badge {
            display: inline-flex;
            align-items: center;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .status-pending {
            background-color: #FEF3C7;
            color: #92400E;
        }
        
        .status-approved {
            background-color: #DBEAFE;
            color: #1E40AF;
        }
        
        .status-ready_for_pickup {
            background-color: #E0E7FF;
            color: #4338CA;
        }
        
        .status-claimed {
            background-color: #D1FAE5;
            color: #065F46;
        }
        
        .status-rejected {
            background-color: #FEE2E2;
            color: #991B1B;
        }
        
        .claim-card {
            transition: all 0.3s ease;
        }
        
        .claim-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .fade-in {
            animation: fadeIn 0.5s ease-out;
        }

        .claims-table th {
            white-space: nowrap;
        }

        .claims-table td {
            vertical-align: top;
        }

        .action-dropdown-menu {
            z-index: 9999;
            width: 12rem;
            background: #081627;
            border: 1px solid #1f2c3f;
            border-radius: 0.75rem;
            box-shadow: 0 20px 32px rgba(2, 6, 23, 0.45);
            padding: 0.35rem;
            overflow: hidden;
        }

        .action-trigger-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.45rem;
            min-width: 110px;
            border: 1px solid #1f2c3f;
            border-radius: 0.65rem;
            padding: 0.45rem 0.75rem;
            font-size: 0.76rem;
            font-weight: 700;
            color: #f8fafc;
            background: linear-gradient(180deg, #0f2238 0%, #081627 100%);
            transition: background-color 0.2s ease, border-color 0.2s ease;
        }

        .action-trigger-btn:hover {
            background: linear-gradient(180deg, #16314d 0%, #0b1d31 100%);
            border-color: #2f425b;
        }

        .action-menu-item {
            width: 100%;
            display: flex;
            align-items: center;
            gap: 0.55rem;
            padding: 0.55rem 0.6rem;
            font-size: 0.76rem;
            line-height: 1.15rem;
            color: #dbe7f7;
            border-radius: 0.5rem;
            transition: background-color 0.18s ease;
        }

        .action-menu-item:hover {
            background: rgba(148, 163, 184, 0.18);
        }

        .action-icon-view {
            color: #3b82f6;
        }

        .action-icon-chat {
            color: #22c55e;
        }

        .action-icon-danger {
            color: #ef4444;
        }

        .ui-card {
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: 16px;
            box-shadow: 0 10px 24px rgba(15, 23, 42, 0.05);
        }

        .ui-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.45rem;
            border: 0;
            border-radius: 10px;
            padding: 0.6rem 0.95rem;
            font-size: 0.86rem;
            font-weight: 700;
            transition: all 0.2s ease;
        }

        .ui-btn-primary {
            background: linear-gradient(132deg, var(--brand), var(--brand-2));
            color: #ffffff;
        }

        .ui-btn-primary:hover {
            filter: brightness(1.06);
        }

        .ui-btn-soft {
            background: rgba(56, 189, 248, 0.1);
            color: #9aeaff;
            border: 1px solid rgba(56, 189, 248, 0.35);
        }

        .ui-btn-soft:hover {
            background: rgba(56, 189, 248, 0.18);
        }

        .ui-btn-danger {
            background: linear-gradient(135deg, #dc2626, #b91c1c);
            color: #ffffff;
        }

        .ui-modal-backdrop {
            background: rgba(2, 6, 23, 0.58);
        }

        .ui-modal-panel {
            border: 1px solid #dbe7e3;
            box-shadow: 0 24px 40px rgba(2, 6, 23, 0.25);
        }
    </style>
</head>
<body class="claimant-theme">
    <?php include '../includes/components/shared_sidebar.php'; ?>
    
    <main class="main-content">
        <div class="max-w-7xl mx-auto">
            <!-- Header -->
            <div class="mb-8">
                <div class="flex items-center justify-between mb-4">
                    <div>
                        <h1 class="text-3xl font-bold text-gray-900">My Claim Requests</h1>
                        <p class="text-gray-600 mt-2">Track the status of your item claim requests</p>
                    </div>
                    <div class="flex items-center space-x-4">
                        <button onclick="refreshClaims()" class="ui-btn ui-btn-soft">
                            <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                            </svg>
                            Refresh
                        </button>
                        <a href="claimant_all_items.php" class="ui-btn ui-btn-primary">
                            <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                            </svg>
                            Browse Items
                        </a>
                    </div>
                </div>
            </div>
            <!-- Loading State -->
            <div id="loadingState" class="text-center py-16">
                <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-teal-600 mx-auto mb-4"></div>
                <p class="text-gray-600">Loading your claim requests...</p>
            </div>
            
            <!-- Claims Container -->
            <div id="claimsContainer" class="hidden ui-card">
                <!-- Claims will be loaded here -->
            </div>
            
            <!-- Empty State -->
            <div id="emptyState" class="hidden text-center py-16 ui-card">
                <svg class="w-24 h-24 text-gray-300 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                </svg>
                <h3 class="text-2xl font-semibold text-gray-900 mb-2">No Claim Requests Yet</h3>
                <p class="text-gray-600 mb-6 max-w-md mx-auto">You haven't submitted any claim requests. Browse items and submit a claim when you find a match.</p>
                <a href="claimant_all_items.php" class="ui-btn ui-btn-primary">
                    <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                    </svg>
                    Browse Items
                </a>
            </div>
        </div>
    </main>
    
    <!-- Claim Details Modal -->
    <div id="claimModal" class="fixed inset-0 ui-modal-backdrop items-center justify-center z-50 hidden">
        <div class="ui-modal-panel bg-white rounded-2xl max-w-3xl w-full mx-4 max-h-[90vh] overflow-y-auto">
            <div class="p-6">
                <div class="flex justify-between items-center mb-6">
                    <h2 class="text-2xl font-semibold text-gray-900">Claim Request Details</h2>
                    <button onclick="closeClaimModal()" class="text-gray-400 hover:text-gray-600">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </button>
                </div>
                
                <div id="claimModalContent">
                    <div class="flex items-center justify-center py-8">
                        <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-teal-600"></div>
                        <span class="ml-3 text-gray-600">Loading...</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        const CLAIMS_PER_PAGE = 5;
        let allClaims = [];
        let currentClaimsPage = 1;

        async function requestApi(url, options = {}) {
            const requestOptions = Object.assign({
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json'
                }
            }, options);

            const response = await fetch(url, requestOptions);
            const rawText = await response.text();
            const trimmed = rawText.trim();
            let data = null;

            if (trimmed === '') {
                if (url.includes('action=get_my_claims')) {
                    return {
                        success: true,
                        claims: []
                    };
                }
                throw new Error('Server returned an empty response.');
            }

            try {
                data = JSON.parse(trimmed);
            } catch (jsonError) {
                if (response.redirected || /<!doctype html|<html/i.test(trimmed)) {
                    throw new Error('Your session may have expired. Please log in again.');
                }
                throw new Error('Server returned an invalid response format.');
            }

            if (!response.ok || !data || data.success === false) {
                throw new Error(data.error || data.message || ('Request failed with status ' + response.status));
            }

            return data;
        }

        // Load claims on page load
        document.addEventListener('DOMContentLoaded', function() {
            loadClaims();
        });
        
        // Load claims from API
        async function loadClaims() {
            const loadingState = document.getElementById('loadingState');
            const claimsContainer = document.getElementById('claimsContainer');
            const emptyState = document.getElementById('emptyState');

            try {
                const data = await requestApi('api/claim_request_api.php?action=get_my_claims');
                allClaims = Array.isArray(data.claims) ? data.claims : [];
                currentClaimsPage = 1;
                displayClaims(allClaims);
            } catch (error) {
                console.error('Error loading claims:', error);
                loadingState.classList.add('hidden');
                claimsContainer.classList.add('hidden');
                emptyState.classList.remove('hidden');

                const emptyTitle = emptyState.querySelector('h3');
                const emptyText = emptyState.querySelector('p');
                if (emptyTitle) {
                    emptyTitle.textContent = 'No Claim Data Available';
                }
                if (emptyText) {
                    emptyText.textContent = 'No claim records were returned. If you just submitted a claim, refresh in a few seconds.';
                }

                showError(error.message || 'Unable to load claim requests.');
            }
        }
        
        // Display claims in UI
        function displayClaims(claims) {
            const loadingState = document.getElementById('loadingState');
            const claimsContainer = document.getElementById('claimsContainer');
            const emptyState = document.getElementById('emptyState');
            
            loadingState.classList.add('hidden');
            
            if (claims.length === 0) {
                emptyState.classList.remove('hidden');
                claimsContainer.classList.add('hidden');
                return;
            }
            
            emptyState.classList.add('hidden');
            claimsContainer.classList.remove('hidden');

            const totalPages = Math.max(1, Math.ceil(claims.length / CLAIMS_PER_PAGE));
            currentClaimsPage = Math.min(Math.max(currentClaimsPage, 1), totalPages);
            const startIndex = (currentClaimsPage - 1) * CLAIMS_PER_PAGE;
            const pagedClaims = claims.slice(startIndex, startIndex + CLAIMS_PER_PAGE);

            claimsContainer.innerHTML = `
                <table class="claims-table min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left font-semibold text-gray-700">Item</th>
                            <th class="px-4 py-3 text-left font-semibold text-gray-700">Category</th>
                            <th class="px-4 py-3 text-left font-semibold text-gray-700">Status</th>
                            <th class="px-4 py-3 text-left font-semibold text-gray-700">Submitted</th>
                            <th class="px-4 py-3 text-left font-semibold text-gray-700">Match Score</th>
                            <th class="px-4 py-3 text-left font-semibold text-gray-700">Admin Response</th>
                            <th class="px-4 py-3 text-left font-semibold text-gray-700">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 bg-white">
                        ${pagedClaims.map(claim => `
                            <tr class="hover:bg-gray-50 transition-colors">
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-3">
                                        ${claim.item_image ?
                                            `<img src="${claim.item_image}" alt="${claim.item_title}" class="w-12 h-12 rounded-md object-cover" onerror="this.src='https://via.placeholder.com/48?text=No+Image'">` :
                                            `<div class="w-12 h-12 rounded-md bg-gray-100 flex items-center justify-center">
                                                <svg class="w-5 h-5 text-gray-400" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M4 3a2 2 0 00-2 2v10a2 2 0 002 2h12a2 2 0 002-2V5a2 2 0 00-2-2H4zm12 12H4l4-8 3 6 2-4 3 6z" clip-rule="evenodd"/></svg>
                                            </div>`
                                        }
                                        <div>
                                            <p class="font-semibold text-gray-900">${claim.item_title}</p>
                                            <p class="text-xs text-gray-500">#${claim.claim_id}</p>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-gray-700">${claim.item_category}</td>
                                <td class="px-4 py-3"><span class="status-badge status-${claim.status}">${claim.status_text}</span></td>
                                <td class="px-4 py-3 text-gray-700">${claim.created_at_formatted}</td>
                                <td class="px-4 py-3 text-gray-700 font-semibold">${Math.round(claim.similarity_score)}%</td>
                                <td class="px-4 py-3 text-gray-700 max-w-xs">
                                    ${claim.admin_response ? `<p class="line-clamp-2">${claim.admin_response}</p>` : '<span class="text-gray-400">No response yet</span>'}
                                </td>
                                <td class="px-4 py-3">
                                    <div class="relative inline-block text-left action-dropdown" data-claim-id="${claim.claim_id}">
                                        <button type="button" onclick="toggleClaimActionDropdown(${claim.claim_id})" class="action-trigger-btn">
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 5v.01M12 12v.01M12 19v.01"></path>
                                            </svg>
                                            Actions
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                                            </svg>
                                        </button>
                                        <div id="claim-action-dropdown-${claim.claim_id}" class="action-dropdown-menu hidden absolute right-0 mt-2">
                                            <button type="button" onclick="viewClaimDetails(${claim.claim_id}); closeAllClaimActionDropdowns();" class="action-menu-item">
                                                <svg class="w-4 h-4 action-icon-view" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5s8.268 2.943 9.542 7c-1.274 4.057-5.064 7-9.542 7s-8.268-2.943-9.542-7z"></path></svg>
                                                View Details
                                            </button>
                                            ${claim.status === 'pending'
                                                ? `<button type="button" onclick="cancelClaim(${claim.claim_id}); closeAllClaimActionDropdowns();" class="action-menu-item"><svg class="w-4 h-4 action-icon-danger" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>Cancel Claim</button>`
                                                : ''}
                                            ${(claim.status === 'approved' || claim.status === 'ready_for_pickup')
                                                ? `<a href="claimant_matches_chats.php?match_id=${claim.match_id}" onclick="closeAllClaimActionDropdowns();" class="action-menu-item"><svg class="w-4 h-4 action-icon-chat" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"></path></svg>Open Chat</a>`
                                                : ''}
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>
                ${totalPages > 1 ? `
                    <div class="flex items-center justify-between px-4 py-3 border-t border-gray-200 bg-white">
                        <p class="text-xs text-gray-600">Showing ${startIndex + 1}-${Math.min(startIndex + CLAIMS_PER_PAGE, claims.length)} of ${claims.length} claims</p>
                        <div class="flex items-center gap-2">
                            <button type="button" onclick="changeClaimsPage(${currentClaimsPage - 1})" class="px-3 py-1.5 text-xs rounded-md border border-gray-300 bg-white text-gray-700 hover:bg-gray-50 ${currentClaimsPage === 1 ? 'opacity-50 cursor-not-allowed' : ''}" ${currentClaimsPage === 1 ? 'disabled' : ''}>Previous</button>
                            <span class="text-xs text-gray-600">Page ${currentClaimsPage} of ${totalPages}</span>
                            <button type="button" onclick="changeClaimsPage(${currentClaimsPage + 1})" class="px-3 py-1.5 text-xs rounded-md border border-gray-300 bg-white text-gray-700 hover:bg-gray-50 ${currentClaimsPage === totalPages ? 'opacity-50 cursor-not-allowed' : ''}" ${currentClaimsPage === totalPages ? 'disabled' : ''}>Next</button>
                        </div>
                    </div>
                ` : ''}
            `;
        }

        function changeClaimsPage(page) {
            const totalPages = Math.max(1, Math.ceil(allClaims.length / CLAIMS_PER_PAGE));
            const nextPage = Math.min(Math.max(page, 1), totalPages);

            if (nextPage === currentClaimsPage) {
                return;
            }

            currentClaimsPage = nextPage;
            closeAllClaimActionDropdowns();
            displayClaims(allClaims);
        }

        function closeAllClaimActionDropdowns() {
            document.querySelectorAll('[id^="claim-action-dropdown-"]').forEach(menu => {
                menu.classList.add('hidden');
            });
        }

        function toggleClaimActionDropdown(claimId) {
            const targetMenu = document.getElementById(`claim-action-dropdown-${claimId}`);
            if (!targetMenu) return;

            const willOpen = targetMenu.classList.contains('hidden');
            closeAllClaimActionDropdowns();

            if (willOpen) {
                targetMenu.classList.remove('hidden');
            }
        }
        
        // Refresh claims
        function refreshClaims() {
            const loadingState = document.getElementById('loadingState');
            loadingState.classList.remove('hidden');
            document.getElementById('claimsContainer').classList.add('hidden');
            document.getElementById('emptyState').classList.add('hidden');
            
            loadClaims();
        }
        
        // View claim details
        async function viewClaimDetails(claimId) {
            const modal = document.getElementById('claimModal');
            const modalContent = document.getElementById('claimModalContent');
            
            // Show modal with loading state
            modal.classList.remove('hidden');
            modal.classList.add('flex');
            modalContent.innerHTML = `
                <div class="flex items-center justify-center py-8">
                    <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-teal-600"></div>
                    <span class="ml-3 text-gray-600">Loading claim details...</span>
                </div>
            `;
            
            try {
                const data = await requestApi('api/claim_request_api.php?action=get_claim_details&claim_id=' + claimId);
                if (data.success) {
                    const claim = data.claim;
                    modalContent.innerHTML = `
                        <div class="space-y-6">
                            <div class="bg-gray-50 rounded-lg p-4 border-l-4 ${
                                claim.status === 'approved' ? 'border-green-500' :
                                claim.status === 'rejected' ? 'border-red-500' : 'border-yellow-500'
                            }">
                                <div class="flex items-center justify-between">
                                    <div>
                                        <h3 class="text-lg font-semibold text-gray-900">Claim Request #${claim.claim_id}</h3>
                                        <p class="text-sm text-gray-600">Submitted on ${claim.created_at_formatted}</p>
                                    </div>
                                    <span class="status-badge status-${claim.status}">${claim.status_text}</span>
                                </div>
                            </div>

                            <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
                                <div class="space-y-4">
                                    <div class="bg-white border border-gray-200 rounded-lg p-4">
                                        <h4 class="font-semibold text-gray-900 inline-flex items-center gap-2 mb-3">
                                            <svg class="w-5 h-5 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7h18M3 12h18M3 17h18"/></svg>
                                            Item Information
                                        </h4>
                                        <div class="flex gap-3">
                                            ${claim.item_image ?
                                                `<img src="${claim.item_image}" alt="${claim.item_title}" class="w-28 h-28 object-cover rounded-lg" onerror="this.src='https://via.placeholder.com/120?text=No+Image'">` :
                                                `<div class="w-28 h-28 rounded-lg bg-gray-100 flex items-center justify-center"><svg class="w-10 h-10 text-gray-400" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M4 3a2 2 0 00-2 2v10a2 2 0 002 2h12a2 2 0 002-2V5a2 2 0 00-2-2H4zm12 12H4l4-8 3 6 2-4 3 6z" clip-rule="evenodd"/></svg></div>`
                                            }
                                            <div class="space-y-1 text-sm">
                                                <p class="font-semibold text-gray-900">${claim.item_title}</p>
                                                <p class="text-green-700 font-medium">${claim.item_category}</p>
                                                <p class="text-gray-600">${claim.item_description || 'No description provided'}</p>
                                                <p class="text-gray-700"><strong>Match Score:</strong> ${Math.round(claim.similarity_score)}%</p>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="bg-white border border-gray-200 rounded-lg p-4">
                                        <h4 class="font-semibold text-gray-900 inline-flex items-center gap-2 mb-3">
                                            <svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>
                                            Messages
                                        </h4>
                                        <p class="text-sm text-gray-700 mb-2"><strong>Your Message:</strong> ${claim.request_message || 'No message provided.'}</p>
                                        <p class="text-sm text-gray-700"><strong>Admin Response:</strong> ${claim.admin_response || 'No response yet.'}</p>
                                        ${claim.admin_name ? `<p class="text-xs text-gray-500 mt-2">Responded by ${claim.admin_name} on ${claim.updated_at_formatted}</p>` : ''}
                                    </div>
                                </div>

                                <div class="space-y-4">
                                    <div class="bg-white border border-gray-200 rounded-lg p-4">
                                        <h4 class="font-semibold text-gray-900 inline-flex items-center gap-2 mb-3">
                                            <svg class="w-5 h-5 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3M3 12a9 9 0 1018 0 9 9 0 00-18 0z"/></svg>
                                            Timeline
                                        </h4>
                                        <div class="space-y-3 text-sm">
                                            <div>
                                                <p class="font-medium text-gray-900">Claim Submitted</p>
                                                <p class="text-gray-600">${claim.created_at_formatted}</p>
                                            </div>
                                            ${claim.status !== 'pending' ? `
                                            <div>
                                                <p class="font-medium text-gray-900">Claim ${claim.status === 'rejected' ? 'Rejected' : 'Approved'}</p>
                                                <p class="text-gray-600">${claim.updated_at_formatted}</p>
                                            </div>` : ''}
                                            ${(claim.status === 'ready_for_pickup' || claim.status === 'claimed') ? `
                                            <div>
                                                <p class="font-medium text-gray-900">Ready for Pickup</p>
                                                <p class="text-gray-600">SMS notification sent</p>
                                            </div>` : ''}
                                            ${claim.status === 'claimed' ? `
                                            <div>
                                                <p class="font-medium text-gray-900">Item Claimed</p>
                                                <p class="text-gray-600">Successfully picked up</p>
                                            </div>` : ''}
                                        </div>
                                    </div>

                                    <div class="bg-white border border-gray-200 rounded-lg p-4">
                                        <h4 class="font-semibold text-gray-900 inline-flex items-center gap-2 mb-2">
                                            <svg class="w-5 h-5 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M12 2a10 10 0 100 20 10 10 0 000-20z"/></svg>
                                            Status Notes
                                        </h4>
                                        <p class="text-sm text-gray-700">
                                            ${claim.status === 'approved' ? 'Your claim is approved and being prepared for pickup.' : ''}
                                            ${claim.status === 'ready_for_pickup' ? 'Item is ready for pickup. Please visit the office.' : ''}
                                            ${claim.status === 'claimed' ? 'Item was successfully claimed and this process is complete.' : ''}
                                            ${claim.status === 'pending' ? 'Your claim is pending admin review.' : ''}
                                            ${claim.status === 'rejected' ? 'Your claim was rejected. Check admin response for details.' : ''}
                                        </p>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Action Buttons -->
                            <div class="flex gap-3 pt-2">
                                ${claim.status === 'pending' ? `
                                <button onclick="cancelClaim(${claim.claim_id}); closeClaimModal();" class="flex-1 ui-btn ui-btn-danger px-6 py-3">
                                    Cancel Request
                                </button>
                                ` : ''}
                                ${claim.status === 'approved' || claim.status === 'ready_for_pickup' ? `
                                <a href="claimant_matches_chats.php?match_id=${claim.match_id}" class="flex-1 ui-btn ui-btn-primary px-6 py-3 text-center inline-flex items-center justify-center">
                                    <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"></path>
                                    </svg>
                                    Chat with Admin
                                </a>
                                ` : ''}
                                <button onclick="closeClaimModal()" class="${claim.status === 'pending' || claim.status === 'approved' || claim.status === 'ready_for_pickup' ? 'flex-1' : 'w-full'} ui-btn ui-btn-soft px-6 py-3">
                                    Close
                                </button>
                            </div>
                        </div>
                    `;
                } else {
                    modalContent.innerHTML = `
                        <div class="text-center py-8">
                            <svg class="w-16 h-16 text-red-400 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                            <p class="text-gray-600">${data.error || 'Failed to load claim details'}</p>
                            <button onclick="closeClaimModal()" class="mt-4 bg-gray-200 hover:bg-gray-300 text-gray-800 px-6 py-2 rounded-lg font-medium transition-colors">
                                Close
                            </button>
                        </div>
                    `;
                }
            } catch (error) {
                console.error('Error loading claim details:', error);
                modalContent.innerHTML = `
                    <div class="text-center py-8">
                        <svg class="w-16 h-16 text-red-400 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                        <p class="text-gray-600">${error.message || 'Unable to load claim details.'}</p>
                        <button onclick="closeClaimModal()" class="mt-4 bg-gray-200 hover:bg-gray-300 text-gray-800 px-6 py-2 rounded-lg font-medium transition-colors">
                            Close
                        </button>
                    </div>
                `;
            }
        }
        
        // Cancel claim request
        async function cancelClaim(claimId) {
            // Create custom confirmation modal
            const confirmModal = document.createElement('div');
            confirmModal.className = 'fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 fade-in';
            confirmModal.innerHTML = `
                <div class="bg-white rounded-2xl max-w-md w-full mx-4 p-6 shadow-2xl">
                    <div class="text-center">
                        <div class="mx-auto flex items-center justify-center h-12 w-12 rounded-full bg-red-100 mb-4">
                            <svg class="h-6 w-6 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                            </svg>
                        </div>
                        <h3 class="text-lg font-semibold text-gray-900 mb-2">Cancel Claim Request?</h3>
                        <p class="text-sm text-gray-600 mb-6">Are you sure you want to cancel this claim request? This action cannot be undone and you will need to submit a new claim if you change your mind.</p>
                        <div class="flex gap-3">
                            <button onclick="this.closest('.fixed').remove()" class="flex-1 ui-btn ui-btn-soft px-4 py-2.5">
                                No, Keep It
                            </button>
                            <button id="confirmCancelBtn" class="flex-1 ui-btn ui-btn-danger px-4 py-2.5">
                                Yes, Cancel Request
                            </button>
                        </div>
                    </div>
                </div>
            `;
            document.body.appendChild(confirmModal);
            
            // Handle confirmation
            document.getElementById('confirmCancelBtn').addEventListener('click', async function() {
                const btn = this;
                const originalText = btn.innerHTML;
                
                // Show loading state
                btn.disabled = true;
                btn.innerHTML = `
                    <svg class="animate-spin h-5 w-5 mx-auto" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                `;
                
                try {
                    const formData = new FormData();
                    formData.append('action', 'cancel_claim');
                    formData.append('claim_id', claimId);

                    const data = await requestApi('api/claim_request_api.php', {
                        method: 'POST',
                        body: formData,
                        headers: {
                            'Accept': 'application/json'
                        }
                    });
                    
                    if (data.success) {
                        // Show success state
                        btn.innerHTML = `
                            <svg class="h-5 w-5 mx-auto" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                            </svg>
                        `;
                        btn.classList.remove('bg-red-600', 'hover:bg-red-700');
                        btn.classList.add('bg-green-600');
                        
                        setTimeout(() => {
                            confirmModal.remove();
                            showSuccess(data.message || 'Claim request cancelled successfully');
                            refreshClaims();
                        }, 800);
                    } else {
                        throw new Error(data.error || 'Failed to cancel claim');
                    }
                } catch (error) {
                    console.error('Error cancelling claim:', error);
                    
                    // Show error state
                    btn.innerHTML = `
                        <svg class="h-5 w-5 mx-auto" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    `;
                    
                    setTimeout(() => {
                        btn.disabled = false;
                        btn.innerHTML = originalText;
                        showError(error.message || 'Network error');
                    }, 1000);
                }
            });
        }
        
        // Close modal
        function closeClaimModal() {
            document.getElementById('claimModal').classList.add('hidden');
            document.getElementById('claimModal').classList.remove('flex');
        }
        
        // Show success message
        function showSuccess(message) {
            const toast = document.createElement('div');
            toast.className = 'fixed top-4 right-4 bg-green-500 text-white px-6 py-3 rounded-lg shadow-lg z-50 fade-in';
            toast.textContent = message;
            document.body.appendChild(toast);
            
            setTimeout(() => {
                toast.style.opacity = '0';
                setTimeout(() => toast.remove(), 300);
            }, 3000);
        }
        
        // Show error message
        function showError(message) {
            const toast = document.createElement('div');
            toast.className = 'fixed top-4 right-4 bg-red-500 text-white px-6 py-3 rounded-lg shadow-lg z-50 fade-in';
            toast.textContent = message;
            document.body.appendChild(toast);
            
            setTimeout(() => {
                toast.style.opacity = '0';
                setTimeout(() => toast.remove(), 300);
            }, 3000);
        }
        
        // Close modal on outside click
        document.addEventListener('click', function(event) {
            if (!event.target.closest('.action-dropdown')) {
                closeAllClaimActionDropdowns();
            }

            const modal = document.getElementById('claimModal');
            if (event.target === modal) {
                closeClaimModal();
            }
        });
    </script>
</body>
</html>

