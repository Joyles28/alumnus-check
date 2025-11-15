/**
 * Alumni Profile JavaScript
 */

(function() {
	'use strict';

	/**
	 * Toggle like button state
	 */
	window.alumnus_toggleLike = function(btn) {
		if (btn.classList.contains('liked')) {
			btn.classList.remove('liked');
			btn.querySelector('.apc-action-text').textContent = 'Like';
		} else {
			btn.classList.add('liked');
			btn.querySelector('.apc-action-text').textContent = 'Liked';
		}
	};

	/**
	 * Open the edit profile modal
	 */
	window.alumnus_openModal = function() {
		var modalOverlay = document.getElementById('alumnus-modal-overlay');
		if (modalOverlay) {
			// Enable transitions before opening (only after first interaction)
			modalOverlay.classList.add('alumnus-modal-ready');
			// Small delay to ensure transition class is applied before showing
			setTimeout(function() {
				modalOverlay.classList.add('active');
			}, 10);
		}
	};

	/**
	 * Close the edit profile modal
	 */
	window.alumnus_closeModal = function() {
		var modalOverlay = document.getElementById('alumnus-modal-overlay');
		if (modalOverlay) {
			modalOverlay.classList.remove('active');
		}
	};

	/**
	 * Submit bio field via AJAX
	 */
	window.alumnus_submitAllFields = function() {
		var root = document.getElementById('alumnus-profile-root');
		if (!root) return;

		var ajaxUrl = root.getAttribute('data-ajax-url');
		var userId  = root.getAttribute('data-user-id');
		
		var bioInput = document.getElementById('alumnus-modal-bio-input');
		var saveBtn = document.getElementById('alumnus-modal-save');

		if (!ajaxUrl || !userId || !bioInput) return;

		// Get localized strings
		var strings = window.alumnusProfileStrings || {};

		// Disable save button
		if (saveBtn) { 
			saveBtn.disabled = true; 
			saveBtn.textContent = strings.saving || 'Saving…'; 
		}

		// Update Bio
		var bioPayload = new FormData();
		bioPayload.append('action', 'alumnus_update_bio_note');
		bioPayload.append('_ajax_nonce', root.getAttribute('data-nonce-bio'));
		bioPayload.append('user_id', userId);
		bioPayload.append('bio_note', bioInput.value);

		fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: bioPayload })
			.then(function(res){ return res.json(); })
			.then(function(json){
				if (json && json.success) {
					var view = document.getElementById('alumnus-bio-view');
					if (view) { view.innerHTML = json.data.html; }
					window.alumnus_closeModal();
				} else {
					alert((json && json.data && json.data.message) ? json.data.message : (strings.errorBio || 'Failed to update bio.'));
				}
			})
			.catch(function(){ 
				alert(strings.networkErrorBio || 'Network error updating bio.'); 
			})
			.finally(function(){
				if (saveBtn) { 
					saveBtn.disabled = false; 
					saveBtn.textContent = strings.saveChanges || 'Save Changes'; 
				}
			});
	};

	/**
	 * Initialize modal event listeners
	 */
	function initializeModal() {
		var modalOverlay = document.getElementById('alumnus-modal-overlay');
		var modalClose = document.getElementById('alumnus-modal-close');
		var modalSave = document.getElementById('alumnus-modal-save');
		var modalCancel = document.getElementById('alumnus-modal-cancel');
		var bioInput = document.getElementById('alumnus-modal-bio-input');
		var bioCharCount = document.getElementById('alumnus-bio-char-count');

		if (modalClose) {
			modalClose.addEventListener('click', window.alumnus_closeModal);
		}

		if (modalSave) {
			modalSave.addEventListener('click', window.alumnus_submitAllFields);
		}

		if (modalCancel) {
			modalCancel.addEventListener('click', window.alumnus_closeModal);
		}

		if (modalOverlay) {
			modalOverlay.addEventListener('click', function(event) {
				// Close only if clicking the overlay itself, not the card
				if (event.target === modalOverlay) {
					window.alumnus_closeModal();
				}
			});
		}

		// Update bio character counter
		if (bioInput && bioCharCount) {
			// Initialize the count from the textarea value
			bioCharCount.textContent = bioInput.value.length;
			
			// Update on input
			bioInput.addEventListener('input', function() {
				bioCharCount.textContent = bioInput.value.length;
			});
		}
	}

	// Initialize when DOM is ready
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', function(){
			initializeModal();
			alumnus_initSkillsToggle();
			alumnus_initSkillsUI();
			alumnus_initExperienceUI();
		});
	} else {
		initializeModal();
		alumnus_initSkillsToggle();
		alumnus_initSkillsUI();
		alumnus_initExperienceUI();
	}

})();

/**
 * Skills list toggle: show only first two by default, expand/collapse on click.
 * Called on DOM ready and after AJAX updates.
 */
function alumnus_initSkillsToggle(rootEl) {
	var root = rootEl || document;
	var wrappers = root.querySelectorAll('.apc-skills-wrapper');
	wrappers.forEach(function(wrapper){
		var list = wrapper.querySelector('.apc-skills-list');
		var toggle = wrapper.querySelector('.apc-skills-toggle');
		if (!list || !toggle) return;

		var count = parseInt(list.getAttribute('data-skill-count') || '0', 10);
		if (isNaN(count) || count <= 2) {
			wrapper.classList.add('expanded');
			toggle.style.display = 'none';
			return;
		}

		wrapper.classList.remove('expanded');
		toggle.setAttribute('aria-expanded', 'false');

		toggle.addEventListener('click', function(){
			var expanded = wrapper.classList.toggle('expanded');
			toggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
			toggle.textContent = expanded ? 'Show less' : ('Show all skills (' + count + ')');
		});
	});
}

/**
 * Skills: open/close modal and submit skills via AJAX
 */
function alumnus_initSkillsUI() {
	var root = document.getElementById('alumnus-profile-root');
	if (!root) return;

	var addBtn = document.getElementById('alumnus-skills-add-btn');
	var overlay = document.getElementById('alumnus-skills-modal-overlay');
	var closeBtn = document.getElementById('alumnus-skills-modal-close');
	var cancelBtn = document.getElementById('alumnus-skills-cancel');
	var saveBtn = document.getElementById('alumnus-skills-save');
	var skillsInput = document.getElementById('alumnus-skills-modal-input');

	function open() {
		if (!overlay) return;
		overlay.classList.add('alumnus-modal-ready');
		setTimeout(function(){ overlay.classList.add('active'); }, 10);
	}

	function close() {
		if (!overlay) return;
		overlay.classList.remove('active');
	}

	if (addBtn) addBtn.addEventListener('click', open);
	if (closeBtn) closeBtn.addEventListener('click', close);
	if (cancelBtn) cancelBtn.addEventListener('click', close);
	if (overlay) {
		overlay.addEventListener('click', function(e){ if (e.target === overlay) close(); });
	}

	if (saveBtn && skillsInput) {
		saveBtn.addEventListener('click', function(){
			var ajaxUrl = root.getAttribute('data-ajax-url');
			var userId = root.getAttribute('data-user-id');
			var nonce = root.getAttribute('data-nonce-skills');

			var skills = skillsInput.value.trim();

			var strings = window.alumnusProfileStrings || {};
			saveBtn.disabled = true;
			saveBtn.textContent = strings.savingSkills || 'Saving…';

			var payload = new FormData();
			payload.append('action', 'alumnus_update_skills');
			payload.append('_ajax_nonce', nonce);
			payload.append('user_id', userId);
			payload.append('skills', skills);

			fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: payload })
				.then(function(res){ return res.json(); })
				.then(function(json){
					if (json && json.success) {
						var view = document.getElementById('alumnus-skills-view');
						if (view) {
							view.innerHTML = json.data.html;
							alumnus_initSkillsToggle(view);
						}
						close();
					} else {
						alert((json && json.data && json.data.message) ? json.data.message : (strings.errorSkills || 'Failed to update skills.'));
					}
				})
				.catch(function(){
					alert(strings.networkErrorSkills || 'Network error updating skills.');
				})
				.finally(function(){
					saveBtn.disabled = false;
					saveBtn.textContent = strings.saveChanges || 'Save';
				});
		});
	}
}

/**
 * Experience: open/close modal and submit new experience via AJAX
 */
function alumnus_initExperienceUI() {
	var root = document.getElementById('alumnus-profile-root');
	if (!root) return;

	var addBtn = document.getElementById('alumnus-exp-add-btn');
	var overlay = document.getElementById('alumnus-exp-modal-overlay');
	var closeBtn = document.getElementById('alumnus-exp-modal-close');
	var cancelBtn = document.getElementById('alumnus-exp-cancel');
	var saveBtn = document.getElementById('alumnus-exp-save');
	var currentChk = document.getElementById('alumnus-exp-current');
	var endInput = document.getElementById('alumnus-exp-end');

	// Edit modal elements
	var editOverlay = document.getElementById('alumnus-exp-edit-modal-overlay');
	var editCloseBtn = document.getElementById('alumnus-exp-edit-modal-close');
	var editCancelBtn = document.getElementById('alumnus-exp-edit-cancel');
	var editSaveBtn = document.getElementById('alumnus-exp-edit-save');
	var editId = document.getElementById('alumnus-exp-edit-id');
	var editTitle = document.getElementById('alumnus-exp-edit-title');
	var editCompany = document.getElementById('alumnus-exp-edit-company');
	var editLocation = document.getElementById('alumnus-exp-edit-location');
	var editStart = document.getElementById('alumnus-exp-edit-start');
	var editEnd = document.getElementById('alumnus-exp-edit-end');
	var editCurrent = document.getElementById('alumnus-exp-edit-current');

	function open() {
		if (!overlay) return;
		overlay.classList.add('alumnus-modal-ready');
		setTimeout(function(){ overlay.classList.add('active'); }, 10);
	}
	function close() {
		if (!overlay) return;
		overlay.classList.remove('active');
		
		// Clear form inputs when closing modal
		var titleInput = document.getElementById('alumnus-exp-title');
		var companyInput = document.getElementById('alumnus-exp-company');
		var locationInput = document.getElementById('alumnus-exp-location');
		var startInput = document.getElementById('alumnus-exp-start');
		
		if (titleInput) titleInput.value = '';
		if (companyInput) companyInput.value = '';
		if (locationInput) locationInput.value = '';
		if (startInput) startInput.value = '';
		if (endInput) {
			endInput.value = '';
			endInput.disabled = false;
		}
		if (currentChk) currentChk.checked = false;
	}

	function openEdit() {
		if (!editOverlay) return;
		editOverlay.classList.add('alumnus-modal-ready');
		setTimeout(function(){ editOverlay.classList.add('active'); }, 10);
	}
	function closeEdit() {
		if (!editOverlay) return;
		editOverlay.classList.remove('active');
	}

	if (addBtn) addBtn.addEventListener('click', open);
	if (closeBtn) closeBtn.addEventListener('click', close);
	if (cancelBtn) cancelBtn.addEventListener('click', close);
	if (overlay) {
		overlay.addEventListener('click', function(e){ if (e.target === overlay) close(); });
	}

	if (editOverlay) {
		editOverlay.addEventListener('click', function(e){ if (e.target === editOverlay) closeEdit(); });
	}

	function wireEndDateToggle(currentChk, endInput) {
		if (!currentChk || !endInput) return;
		function toggle() {
			endInput.disabled = currentChk.checked;
			if (currentChk.checked) endInput.value = '';
		}
		currentChk.addEventListener('change', toggle);
		toggle();
	}

	if (currentChk && endInput) {
		wireEndDateToggle(currentChk, endInput);
	}

	if (saveBtn) {
		saveBtn.addEventListener('click', function(){
			var ajaxUrl = root.getAttribute('data-ajax-url');
			var userId = root.getAttribute('data-user-id');
			var nonce = root.getAttribute('data-nonce-exp');

			var title = document.getElementById('alumnus-exp-title').value.trim();
			var company = document.getElementById('alumnus-exp-company').value.trim();
			var location = document.getElementById('alumnus-exp-location').value.trim();
			var start = document.getElementById('alumnus-exp-start').value;
			var end = document.getElementById('alumnus-exp-end').value;

			if (!title || !company || !start) {
				alert('Please fill in Title, Company, and Start date.');
				return;
			}

			var strings = window.alumnusProfileStrings || {};
			saveBtn.disabled = true;
			saveBtn.textContent = strings.savingExperience || 'Saving Experience…';

			var payload = new FormData();
			payload.append('action', 'alumnus_add_experience');
			payload.append('_ajax_nonce', nonce);
			payload.append('user_id', userId);
			payload.append('title', title);
			payload.append('company_name', company);
			payload.append('location', location);
			payload.append('start_date', start);
			payload.append('end_date', currentChk && currentChk.checked ? '' : end);

			fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: payload })
				.then(function(res){ return res.json(); })
				.then(function(json){
					if (json && json.success) {
						var view = document.getElementById('alumnus-experience-view');
						if (view) view.innerHTML = json.data.html;
						close();
					} else {
						alert((json && json.data && json.data.message) ? json.data.message : (strings.errorExperience || 'Failed to add experience.'));
					}
				})
				.catch(function(){
					alert(strings.networkErrorExperience || 'Network error adding experience.');
				})
				.finally(function(){
					saveBtn.disabled = false;
					saveBtn.textContent = 'Save';
				});
		});
	}

	// Wire up Edit buttons (event delegation)
	var expView = document.getElementById('alumnus-experience-view');
	if (expView) {
		expView.addEventListener('click', function(e){
			var editBtn = e.target.closest('.apc-exp-edit');
			var delBtn = e.target.closest('.apc-exp-delete');
			var ajaxUrl = root.getAttribute('data-ajax-url');
			var userId = root.getAttribute('data-user-id');
			var nonce = root.getAttribute('data-nonce-exp');

			if (editBtn) {
				var li = editBtn.closest('.apc-exp-item');
				if (!li) return;
				var id = editBtn.getAttribute('data-exp-id');
				var titleEl = li.querySelector('.apc-exp-title');
				var title = titleEl ? titleEl.textContent.trim() : '';
				var company = li.getAttribute('data-company') || '';
				var location = li.getAttribute('data-location') || '';
			var startRaw = li.getAttribute('data-start') || '';
			var endRaw = li.getAttribute('data-end') || '';
			// Normalize sentinel values sometimes used by MySQL or backends
			if (endRaw === '0000-00-00' || endRaw === 'null' || endRaw === 'undefined') {
				endRaw = '';
			}

			// Convert YYYY-MM-DD to YYYY-MM for month input
			var startMonth = startRaw ? startRaw.substring(0, 7) : '';
			var endMonth = endRaw ? endRaw.substring(0, 7) : '';

			editId.value = id;
			editTitle.value = title;
			editCompany.value = company;
			editLocation.value = location;
			editStart.value = startMonth;
			if (!endMonth) {
				editEnd.value = '';
				editCurrent.checked = true;
			} else {
				editEnd.value = endMonth;
				editCurrent.checked = false;
			}
				if (editCurrent && editEnd) { editEnd.disabled = editCurrent.checked; }
				wireEndDateToggle(editCurrent, editEnd);
				openEdit();
				return;
			}

			if (delBtn) {
				var deleteId = delBtn.getAttribute('data-exp-id');
				if (!deleteId) return;
				if (!confirm('Delete this experience?')) return;
				var payload = new FormData();
				payload.append('action', 'alumnus_delete_experience');
				payload.append('_ajax_nonce', nonce);
				payload.append('user_id', userId);
				payload.append('experience_id', deleteId);
				fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: payload })
					.then(function(res){ return res.json(); })
					.then(function(json){
						if (json && json.success) {
							if (expView) expView.innerHTML = json.data.html;
						} else {
							alert((json && json.data && json.data.message) ? json.data.message : 'Failed to delete experience.');
						}
					})
					.catch(function(){ alert('Network error deleting experience.'); });
				return;
			}
		});
	}

	// Save edit
	if (editSaveBtn) {
		editSaveBtn.addEventListener('click', function(){
			var ajaxUrl = root.getAttribute('data-ajax-url');
			var userId = root.getAttribute('data-user-id');
			var nonce = root.getAttribute('data-nonce-exp');

		var id = editId.value;
		var title = editTitle.value.trim();
		var company = editCompany.value.trim();
		var location = editLocation.value.trim();
		var start = editStart.value;
		var end = editEnd.value;
		if (!id || !title || !company || !start) { 
			alert(getLocalizedMessage('errorRequiredFields', 'Please fill in Title, Company, and Start Date.')); 
			return; 
		}

			var payload = new FormData();
			payload.append('action', 'alumnus_update_experience');
			payload.append('_ajax_nonce', nonce);
			payload.append('user_id', userId);
			payload.append('experience_id', id);
			payload.append('title', title);
			payload.append('company_name', company);
			payload.append('location', location);
			payload.append('start_date', start);
			payload.append('end_date', (editCurrent && editCurrent.checked) ? '' : end);

			fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: payload })
				.then(function(res){ return res.json(); })
				.then(function(json){
					if (json && json.success) {
						if (expView) expView.innerHTML = json.data.html;
						closeEdit();
					} else {
						alert((json && json.data && json.data.message) ? json.data.message : 'Failed to update experience.');
					}
				})
				.catch(function(){ alert('Network error updating experience.'); });
		});
	}

	if (editCloseBtn) editCloseBtn.addEventListener('click', closeEdit);
	if (editCancelBtn) editCancelBtn.addEventListener('click', closeEdit);
}

/**
 * Initialize skill autocomplete functionality
 */
function initSkillAutocomplete() {
	var skillInput = document.getElementById('alumnus-skills-modal-input');
	if (!skillInput) return;
	
	// Check if dropdown already exists
	var existingDropdown = document.getElementById('skill-autocomplete-dropdown');
	if (existingDropdown) {
		existingDropdown.parentNode.removeChild(existingDropdown);
	}
	
	// Create autocomplete dropdown container
	var dropdown = document.createElement('div');
	dropdown.id = 'skill-autocomplete-dropdown';
	dropdown.className = 'skill-autocomplete-dropdown';
	dropdown.style.display = 'none';
	
	// Insert directly after the textarea (before the hint text)
	skillInput.parentNode.insertBefore(dropdown, skillInput.nextSibling);
	
	var debounceTimer;
	
	skillInput.addEventListener('input', function(e) {
		clearTimeout(debounceTimer);
		
		// Get the current word being typed
		var cursorPos = this.selectionStart;
		var text = this.value.substring(0, cursorPos);
		var lastCommaIndex = text.lastIndexOf(',');
		var currentWord = text.substring(lastCommaIndex + 1).trim();
		
		if (currentWord.length < 2) {
			dropdown.style.display = 'none';
			return;
		}
		
		// Debounce to avoid too many requests (300ms delay)
		debounceTimer = setTimeout(function() {
			fetchSkillSuggestions(currentWord, dropdown, skillInput);
		}, 300);
	});
	
	// Hide dropdown when clicking outside
	document.addEventListener('click', function(e) {
		if (e.target !== skillInput && !dropdown.contains(e.target)) {
			dropdown.style.display = 'none';
		}
	});
	
	// Handle keyboard navigation
	skillInput.addEventListener('keydown', function(e) {
		if (dropdown.style.display === 'none') return;
		
		var items = dropdown.querySelectorAll('.skill-autocomplete-item');
		if (items.length === 0) return;
		
		var activeItem = dropdown.querySelector('.skill-autocomplete-item.active');
		var activeIndex = -1;
		
		if (activeItem) {
			for (var i = 0; i < items.length; i++) {
				if (items[i] === activeItem) {
					activeIndex = i;
					break;
				}
			}
		}
		
		if (e.key === 'ArrowDown') {
			e.preventDefault();
			var nextIndex = activeIndex + 1;
			if (nextIndex >= items.length) nextIndex = 0;
			
			if (activeItem) activeItem.classList.remove('active');
			items[nextIndex].classList.add('active');
		} else if (e.key === 'ArrowUp') {
			e.preventDefault();
			var prevIndex = activeIndex - 1;
			if (prevIndex < 0) prevIndex = items.length - 1;
			
			if (activeItem) activeItem.classList.remove('active');
			items[prevIndex].classList.add('active');
		} else if (e.key === 'Enter') {
			if (activeItem) {
				e.preventDefault();
				activeItem.click();
			}
		} else if (e.key === 'Escape') {
			dropdown.style.display = 'none';
		}
	});
}

function fetchSkillSuggestions(searchTerm, dropdown, inputField) {
	var strings = window.alumnusProfileStrings || {};
	var ajaxUrl = strings.ajax_url || (window.ajaxurl || '/wp-admin/admin-ajax.php');
	var nonce = strings.search_skills_nonce || '';
	
	if (!nonce) {
		console.error('Missing search_skills_nonce');
		return;
	}
	
	// Use jQuery if available, otherwise use fetch
	if (typeof jQuery !== 'undefined') {
		jQuery.ajax({
			url: ajaxUrl,
			type: 'POST',
			data: {
				action: 'alumnus_search_skills',
				search: searchTerm,
				nonce: nonce
			},
			success: function(response) {
				if (response.success && response.data.skills.length > 0) {
					displaySkillSuggestions(response.data.skills, dropdown, inputField);
				} else {
					dropdown.style.display = 'none';
				}
			},
			error: function() {
				dropdown.style.display = 'none';
			}
		});
	} else {
		// Fallback to fetch API
		var formData = new FormData();
		formData.append('action', 'alumnus_search_skills');
		formData.append('search', searchTerm);
		formData.append('nonce', nonce);
		
		fetch(ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: formData
		})
		.then(function(res) { return res.json(); })
		.then(function(response) {
			if (response.success && response.data.skills.length > 0) {
				displaySkillSuggestions(response.data.skills, dropdown, inputField);
			} else {
				dropdown.style.display = 'none';
			}
		})
		.catch(function() {
			dropdown.style.display = 'none';
		});
	}
}

function displaySkillSuggestions(skills, dropdown, inputField) {
	dropdown.innerHTML = '';
	
	for (var i = 0; i < skills.length; i++) {
		var skill = skills[i];
		var item = document.createElement('div');
		item.className = 'skill-autocomplete-item';
		item.textContent = skill;
		
		// Store skill value as data attribute
		item.setAttribute('data-skill', skill);
		
		item.addEventListener('click', function() {
			var selectedSkill = this.getAttribute('data-skill');
			insertSkill(selectedSkill, inputField);
			dropdown.style.display = 'none';
		});
		
		dropdown.appendChild(item);
	}
	
	dropdown.style.display = 'block';
}

function insertSkill(skill, inputField) {
	var cursorPos = inputField.selectionStart;
	var text = inputField.value;
	var beforeCursor = text.substring(0, cursorPos);
	var afterCursor = text.substring(cursorPos);
	
	// Find where the current word starts
	var lastCommaIndex = beforeCursor.lastIndexOf(',');
	var beforeWord = beforeCursor.substring(0, lastCommaIndex + 1);
	
	// Insert the skill
	var newValue = beforeWord + (beforeWord && !beforeWord.endsWith(' ') ? ' ' : '') + skill + ', ' + afterCursor;
	inputField.value = newValue;
	
	// Position cursor after the inserted skill
	var newCursorPos = (beforeWord + ' ' + skill + ', ').length;
	inputField.setSelectionRange(newCursorPos, newCursorPos);
	inputField.focus();
}

// Initialize autocomplete when skills modal opens
document.addEventListener('DOMContentLoaded', function() {
	var addSkillsBtn = document.getElementById('alumnus-skills-add-btn');
	if (addSkillsBtn) {
		addSkillsBtn.addEventListener('click', function() {
			// Small delay to ensure modal is fully rendered
			setTimeout(function() {
				initSkillAutocomplete();
			}, 100);
		});
	}
});

