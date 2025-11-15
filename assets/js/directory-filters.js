/**
 * Alumni Directory Filters
 * Handles filtering of alumni cards by year and course with pagination
 */

// Auto-load alumni results on initial page view without requiring an explicit Search click.
(function() {
	'use strict';
    
	// Prevent double initialization if script runs twice (DOMContentLoaded + load timeout)
	function initFilters() {
		if (window.AlumnusDirectoryFiltersInitialized) { return; }
		window.AlumnusDirectoryFiltersInitialized = true;
		const yearFilter = document.getElementById('filter-year');
		const courseFilter = document.getElementById('filter-course');
		const searchInput = document.getElementById('alumnus-directory-search');
		const searchTextButton = document.getElementById('alumnus-directory-submit-btn'); // Text button
		const grid = document.getElementById('alumnus-grid');
		const paginationContainer = document.getElementById('alumnus-pagination');
		
		if (!yearFilter || !courseFilter || !grid || typeof AlumnusDirectory === 'undefined') {
			return;
		}
		
		// Track current page
		let currentPage = 1;
		
		// Fetch and render from server
		let currentController = null;
		function fetchAlumni(page) {
			page = page || 1;
			currentPage = page;
			
			const selectedYear = yearFilter.value;
			const selectedCourse = courseFilter.value;
			const searchTerm = searchInput ? searchInput.value : '';
			
			grid.innerHTML = '<div class="no-results-message"><p>' + (AlumnusDirectory?.i18n?.loading || 'Loading...') + '</p></div>';
			if (paginationContainer) {
				paginationContainer.innerHTML = '';
			}
			
			const formData = new FormData();
			formData.append('action', 'alumnus_fetch_alumni');
			formData.append('nonce', AlumnusDirectory.nonce);
			formData.append('year', selectedYear);
			formData.append('course_id', selectedCourse);
			formData.append('search', searchTerm);
			formData.append('profile_url', AlumnusDirectory.profile_url || '');
			formData.append('page', page);
			
			if (currentController) {
				try { currentController.abort(); } catch(e) {}
			}
			
			currentController = new AbortController();
			fetch(AlumnusDirectory.ajax_url, {
				method: 'POST',
				body: formData,
				credentials: 'same-origin',
				signal: currentController.signal
			})
			.then(function(res) { return res.json(); })
			.then(function(json) {
				if (json && json.success && json.data) {
					// Handle new response format with html and pagination
					const data = json.data;
					if (typeof data === 'string') {
						// Legacy format (just HTML string)
						grid.innerHTML = data;
					} else if (data.html) {
						// New format with pagination
						grid.innerHTML = data.html;
						if (data.pagination && paginationContainer) {
							renderPagination(data.pagination);
						}
					}
				} else {
					grid.innerHTML = '<div class="no-results-message"><p>' + (AlumnusDirectory?.i18n?.noResults || 'No alumni found matching your filters.') + '</p></div>';
				}
			})
			.catch(function() {
				grid.innerHTML = '<div class="no-results-message"><p>' + (AlumnusDirectory?.i18n?.noResults || 'No alumni found matching your filters.') + '</p></div>';
			});
		}
		
		// Render pagination controls
		function renderPagination(pagination) {
			if (!paginationContainer) return;
			if (pagination.total_pages <= 1) {
				paginationContainer.innerHTML = '';
				return;
			}
			
			const currentPageNum = pagination.current_page;
			const totalPages = pagination.total_pages;
			
			let html = '<div class="pagination-controls">';
			
			// Previous button
			if (currentPageNum > 1) {
				html += '<button class="pagination-btn pagination-prev" data-page="' + (currentPageNum - 1) + '">Previous</button>';
			} else {
				html += '<button class="pagination-btn pagination-prev" disabled>Previous</button>';
			}
			
			// Page numbers
			html += '<div class="pagination-numbers">';
			
			const pageNumbers = generatePageNumbers(currentPageNum, totalPages);
			for (let i = 0; i < pageNumbers.length; i++) {
				const pageNum = pageNumbers[i];
				if (pageNum === '...') {
					html += '<span class="pagination-ellipsis">...</span>';
				} else {
					const isActive = pageNum === currentPageNum ? ' active' : '';
					html += '<button class="pagination-btn pagination-number' + isActive + '" data-page="' + pageNum + '">' + pageNum + '</button>';
				}
			}
			
			html += '</div>';
			
			// Next button
			if (currentPageNum < totalPages) {
				html += '<button class="pagination-btn pagination-next" data-page="' + (currentPageNum + 1) + '">Next</button>';
			} else {
				html += '<button class="pagination-btn pagination-next" disabled>Next</button>';
			}
			
			html += '</div>';
			
			paginationContainer.innerHTML = html;
			
			// Add click handlers to pagination buttons
			const paginationButtons = paginationContainer.querySelectorAll('.pagination-btn:not([disabled])');
			for (let i = 0; i < paginationButtons.length; i++) {
				paginationButtons[i].addEventListener('click', function() {
					const page = parseInt(this.getAttribute('data-page'), 10);
					if (page && page > 0) {
						fetchAlumni(page);
						// Scroll to top of results
						if (grid) {
							grid.scrollIntoView({ behavior: 'smooth', block: 'start' });
						}
					}
				});
			}
		}
		
		// Generate page numbers array with ellipsis
		function generatePageNumbers(current, total) {
			const pages = [];
			
			if (total <= 7) {
				// Show all pages if 7 or fewer
				for (let i = 1; i <= total; i++) {
					pages.push(i);
				}
			} else {
				// Always show first page
				pages.push(1);
				
				if (current <= 4) {
					// Near the beginning
					for (let i = 2; i <= 5; i++) {
						pages.push(i);
					}
					pages.push('...');
					pages.push(total);
				} else if (current >= total - 3) {
					// Near the end
					pages.push('...');
					for (let i = total - 4; i <= total; i++) {
						pages.push(i);
					}
				} else {
					// In the middle
					pages.push('...');
					for (let i = current - 1; i <= current + 1; i++) {
						pages.push(i);
					}
					pages.push('...');
					pages.push(total);
				}
			}
			
			return pages;
		}
		
		// Add event listeners (no real-time; only on button click or Enter key)
		if (searchTextButton) {
			searchTextButton.addEventListener('click', function() {
				fetchAlumni(1); // Reset to page 1 on new search
			});
		}

		// Also allow Enter key in the search input to trigger search
		if (searchInput) {
			searchInput.addEventListener('keydown', function(e) {
				if (e.key === 'Enter') {
					e.preventDefault();
					fetchAlumni(1); // Reset to page 1 on new search
				}
			});
		}
		
		// Reset to page 1 when filters change
		yearFilter.addEventListener('change', function() {
			// Don't auto-fetch, wait for Search button
			currentPage = 1;
		});
		
		courseFilter.addEventListener('change', function() {
			// Don't auto-fetch, wait for Search button
			currentPage = 1;
		});

		// Initial automatic fetch so users immediately see results.
		// Only trigger if the grid exists and hasn't already been populated during this run.
		if (grid && grid.querySelector('[data-initial="1"]')) {
			fetchAlumni(1);
		} else if (grid && grid.children.length === 0) {
			fetchAlumni(1);
		}
	}
	
	// Initialize on DOM ready
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', initFilters);
	} else {
		initFilters();
	}
	
	// Also initialize after a short delay to catch dynamically loaded content
	window.addEventListener('load', function() {
		setTimeout(initFilters, 100);
	});
})();

