(function(){
  function postForm(url, data){
    return fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
      body: new URLSearchParams(data).toString(),
      credentials: 'same-origin'
    }).then(function(r){ return r.json(); });
  }

  function updateCount(selector, postId, count){
    var nodes = document.querySelectorAll(selector + '[data-post-id="' + postId + '"]');
    nodes.forEach(function(n){
      var txt = n.textContent.replace(/\d+$/, String(count));
      if (!/\d+$/.test(n.textContent)) {
        txt = txt.replace(/[^0-9]*$/, '') + count;
      }
      n.textContent = txt;
    });
  }

  function insertNewPost(html){
    var main = document.querySelector('.alumnus-feed-main');
    var composer = document.querySelector('.alumnus-post-composer');
    if(!main || !html) return;
    var temp = document.createElement('div');
    temp.innerHTML = html.trim();
    var card = temp.firstElementChild;
    if(card){
      if(composer && composer.nextSibling){
        main.insertBefore(card, composer.nextSibling);
      } else {
        main.appendChild(card);
      }
    }
  }

  // LIKE
  function onLikeClick(e){
    var btn = e.target.closest('.btn-like');
    if(!btn) return;
    var postId = btn.getAttribute('data-post-id');
    if(!postId || !window.AlumnusFeed) return;
    btn.disabled = true;
    postForm(AlumnusFeed.ajaxUrl, { action:'alumnus_like_toggle', nonce:AlumnusFeed.nonceLike, postId:postId })
      .then(function(res){
        if(res && res.success){
          btn.classList.toggle('is-active', !!res.data.liked);
          btn.textContent = res.data.liked ? 'Liked' : 'Like';
          updateCount('.pe-like-count', postId, res.data.count);
        }
      })
      .catch(function(){})
      .finally(function(){ btn.disabled=false; });
  }

  // SHARE
  function onShareClick(e){
    var btn = e.target.closest('.btn-share');
    if(!btn) return;
    var postId = btn.getAttribute('data-post-id');
    if(!postId || !window.AlumnusFeed) return;
    btn.disabled = true;
    postForm(AlumnusFeed.ajaxUrl, { action:'alumnus_share_post', nonce:AlumnusFeed.nonceShare, postId:postId })
      .then(function(res){
        if(res && res.success){
          btn.classList.add('is-active');
          btn.textContent = 'Shared';
          updateCount('.pe-share-count', postId, res.data.count);
          if(res.data && res.data.html){ insertNewPost(res.data.html); }
        }
      })
      .catch(function(){})
      .finally(function(){ btn.disabled=false; });
  }

  // MODALS
  function openModal(id){
    var el = document.getElementById(id);
    if(!el) return;
    el.setAttribute('aria-hidden','false');
    el.classList.add('is-visible');
    var focusEl = el.querySelector('textarea, input, button');
    if(focusEl) focusEl.focus();
    document.body.classList.add('alumnus-modal-open');
  }
  function closeModal(el){
    if(!el) return;
    el.classList.remove('is-visible');
    el.setAttribute('aria-hidden','true');
    document.body.classList.remove('alumnus-modal-open');
  }
  document.addEventListener('click', function(e){
    if(e.target.matches('.btn-open-post-modal')){ openModal('alumnus-post-modal'); }
    if(e.target.hasAttribute('data-close-modal')){ closeModal(e.target.closest('.alumnus-modal-overlay')); }
    if(e.target.classList.contains('alumnus-modal-overlay')){ closeModal(e.target); }
    // Open comment modal
    var cbtn = e.target.closest('.btn-comment');
    if(cbtn){
      var postId = cbtn.getAttribute('data-post-id');
      var card = document.querySelector('.alumnus-post-card[data-post-id="'+postId+'"]');
      var modalPost = document.getElementById('alumnus-comment-modal-post');
      var listWrapper = document.getElementById('alumnus-comment-list-wrapper');
      
      // Update modal title with poster's name
      var posterName = '';
      if(card){
        var nameEl = card.querySelector('.ph-name');
        if(nameEl){ posterName = nameEl.textContent.trim(); }
      }
      var modalTitle = document.querySelector('#alumnus-comment-modal .alumnus-modal-title');
      if(modalTitle && posterName){
        modalTitle.textContent = posterName + "'s Post";
      }
      
      if(modalPost){
        modalPost.innerHTML = '';
        if(card){
          // Clone the card sans existing comment form (already removed from feed version)
          var clone = card.cloneNode(true);
          // Remove any nested comments list to avoid duplication; we'll show below separately.
          var existingComments = clone.querySelector('.post-comments');
          if(existingComments){ existingComments.remove(); }
          modalPost.appendChild(clone);
        }
      }
      if(listWrapper){
        listWrapper.innerHTML = '';
        // Try to pull existing comments from feed DOM
        var sourceComments = document.querySelector('#comments-' + postId + ' .comments-list');
        if(sourceComments){
          listWrapper.appendChild(sourceComments.cloneNode(true));
        } else {
          var empty = document.createElement('div');
          empty.className='alumnus-modal-comments-empty';
          empty.innerHTML='<div class="alumnus-modal-comments-empty-icon"><i class="fa-solid fa-comments"></i></div><p class="alumnus-modal-comments-empty-text">No comments yet</p><p class="alumnus-modal-comments-empty-sub">Be the first to comment.</p>';
          listWrapper.appendChild(empty);
        }
      }
      var hiddenInput = document.querySelector('#alumnus-comment-modal-form input[name="postId"]');
      if(hiddenInput){ hiddenInput.value = postId; }
      openModal('alumnus-comment-modal');
    }
  });

  // POST (create new post via modal)
  document.addEventListener('submit', function(e){
    var form = e.target.closest('#alumnus-post-modal-form');
    if(!form) return;
    e.preventDefault();
    var ta = form.querySelector('textarea[name="content"]');
    var val = (ta && ta.value || '').trim();
    if(!val) return;
    form.querySelector('button[type="submit"]').disabled = true;
    postForm(AlumnusFeed.ajaxUrl, { action:'alumnus_add_post', nonce:AlumnusFeed.noncePost, content:val })
      .then(function(res){
        if(res && res.success && res.data && res.data.html){
          insertNewPost(res.data.html);
          ta.value='';
          closeModal(document.getElementById('alumnus-post-modal'));
        }
      })
      .catch(function(){})
      .finally(function(){ form.querySelector('button[type="submit"]').disabled=false; });
  });

  // COMMENT (modal)
  document.addEventListener('submit', function(e){
    var form = e.target.closest('#alumnus-comment-modal-form');
    if(!form) return;
    e.preventDefault();
    var field = form.querySelector('textarea[name="comment"], input[name="comment"]');
    var postIdEl = form.querySelector('input[name="postId"]');
    var postId = postIdEl ? postIdEl.value : '';
    var val = (field && field.value || '').trim();
    if(!val || !postId) return;
    var submitBtn = form.querySelector('button[type="submit"]');
    if(submitBtn) submitBtn.disabled = true;
    postForm(AlumnusFeed.ajaxUrl, { action:'alumnus_add_comment', nonce:AlumnusFeed.nonceComment, postId:postId, content:val })
      .then(function(res){
        if(res && res.success){
          if(res.data && res.data.html){
            // Update feed card comments list
            var feedContainer = document.getElementById('comments-' + postId);
            if(feedContainer){
              // Remove any 'No comments yet' placeholder
              var feedEmpty = feedContainer.querySelector('.apc-placeholder');
              if(feedEmpty){ feedEmpty.remove(); }
              var feedList = feedContainer.querySelector('.comments-list');
              if(!feedList){
                feedList = document.createElement('ul');
                feedList.className='comments-list';
                feedContainer.appendChild(feedList);
              }
              var tmp1 = document.createElement('div'); tmp1.innerHTML = res.data.html; var li1=tmp1.firstElementChild; if(li1){ feedList.insertBefore(li1, feedList.firstChild); }
            }
            // Update modal comments list
            var modalListWrapper = document.getElementById('alumnus-comment-list-wrapper');
            if(modalListWrapper){
              var list = modalListWrapper.querySelector('.comments-list');
              var emptyState = modalListWrapper.querySelector('.alumnus-modal-comments-empty');
              if(emptyState){ emptyState.remove(); }
              if(!list){
                list = document.createElement('ul');
                list.className='comments-list';
                modalListWrapper.appendChild(list);
              }
              var tmp = document.createElement('div'); tmp.innerHTML = res.data.html; var li=tmp.firstElementChild; if(li){ list.insertBefore(li, list.firstChild); }
            }
          }
          if(res.data && typeof res.data.count === 'number'){ updateCount('.pe-comment-count', postId, res.data.count); }
          if(field) field.value='';
          closeModal(document.getElementById('alumnus-comment-modal'));
        }
      })
      .catch(function(){})
      .finally(function(){ if(submitBtn) submitBtn.disabled=false; });
  });

  // Global listeners for like/share remain
  document.addEventListener('click', onLikeClick);
  document.addEventListener('click', onShareClick);

  // RESPONSIVE LAYOUT HANDLER
  var layoutState = null; // 'desktop', 'tablet', 'mobile'
  var originalParents = {};
  
  function initResponsiveLayout(){
    var profileCard = document.querySelector('.alumnus-profile-card');
    var postComposer = document.querySelector('.alumnus-post-composer');
    var sidebarLeft = document.querySelector('.alumnus-feed-sidebar-left');
    var sidebarRight = document.querySelector('.alumnus-feed-sidebar-right');
    
    if(!profileCard || !postComposer || !sidebarLeft || !sidebarRight) return;
    
    // Store original parents
    originalParents.profileCard = sidebarLeft;
    originalParents.postComposer = sidebarRight;
    
    handleLayoutChange();
  }
  
  function handleLayoutChange(){
    var width = window.innerWidth;
    var profileCard = document.querySelector('.alumnus-profile-card');
    var postComposer = document.querySelector('.alumnus-post-composer');
    var sidebarLeft = document.querySelector('.alumnus-feed-sidebar-left');
    var sidebarRight = document.querySelector('.alumnus-feed-sidebar-right');
    var feedMain = document.querySelector('.alumnus-feed-main');
    var welcomeMessage = document.querySelector('.alumnus-welcome-message');
    
    if(!profileCard || !postComposer || !sidebarLeft || !sidebarRight || !feedMain) return;
    
    var newState = width > 1300 ? 'desktop' : (width > 780 ? 'tablet' : 'mobile');
    
    if(layoutState === newState) return; // No change needed
    
    layoutState = newState;
    
    if(newState === 'desktop'){
      // Restore original structure: profile-card in left, post-composer in right
      if(profileCard.parentElement !== sidebarLeft){
        sidebarLeft.appendChild(profileCard);
      }
      if(postComposer.parentElement !== sidebarRight){
        sidebarRight.appendChild(postComposer);
      }
    } else if(newState === 'tablet'){
      // Move post-composer to sidebar-left (after profile-card)
      if(postComposer.parentElement !== sidebarLeft){
        sidebarLeft.appendChild(postComposer);
      }
      // Ensure profile-card is in sidebar-left
      if(profileCard.parentElement !== sidebarLeft){
        sidebarLeft.insertBefore(profileCard, sidebarLeft.firstChild);
      }
    } else if(newState === 'mobile'){
      // Move both to feed-main
      // Order: welcome-message (already there), profile-card, post-composer, posts
      if(welcomeMessage){
        // Insert profile-card after welcome-message
        if(profileCard.parentElement !== feedMain){
          feedMain.insertBefore(profileCard, welcomeMessage.nextSibling);
        }
        // Insert post-composer after profile-card
        if(postComposer.parentElement !== feedMain){
          feedMain.insertBefore(postComposer, profileCard.nextSibling);
        }
      } else {
        // Fallback if no welcome message
        if(profileCard.parentElement !== feedMain){
          feedMain.insertBefore(profileCard, feedMain.firstChild);
        }
        if(postComposer.parentElement !== feedMain){
          feedMain.insertBefore(postComposer, profileCard.nextSibling);
        }
      }
    }
  }
  
  // Debounce resize handler
  var resizeTimeout;
  function onResize(){
    clearTimeout(resizeTimeout);
    resizeTimeout = setTimeout(handleLayoutChange, 150);
  }
  
  // Initialize on DOM ready
  if(document.readyState === 'loading'){
    document.addEventListener('DOMContentLoaded', initResponsiveLayout);
  } else {
    initResponsiveLayout();
  }
  
  window.addEventListener('resize', onResize);
})();
