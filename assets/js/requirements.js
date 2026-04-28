/**
 * Requirements Page JavaScript
 * Handles file upload modals, drag-and-drop, and form submissions
 * Version: 1.3 - Simplified and fixed modal display
 */

(function() {
    'use strict';

    // Wait for DOM and Bootstrap to be ready
    function initRequirements() {
        console.log('Requirements.js: Starting initialization');
        
        // Check if Bootstrap is available
        if (typeof bootstrap === 'undefined') {
            console.error('Bootstrap is not loaded!');
            return;
        }

        // Initialize modals
        const uploadModalEl = document.getElementById('uploadModal');
        const attachModalEl = document.getElementById('attachModal');
        
        let uploadModal = null;
        let attachModal = null;

        if (uploadModalEl) {
            uploadModal = new bootstrap.Modal(uploadModalEl, {
                backdrop: true,
                keyboard: true,
                focus: true
            });
            // Use Bootstrap's default z-index (1050) - same as announcements.php
            // Don't override, let Bootstrap handle it
            console.log('Upload modal initialized');
        } else {
            console.error('Upload modal element not found!');
        }

        if (attachModalEl) {
            attachModal = new bootstrap.Modal(attachModalEl, {
                backdrop: true,
                keyboard: true,
                focus: true
            });
            // Use Bootstrap's default z-index (1050) - same as announcements.php
            // Don't override, let Bootstrap handle it
            console.log('Attach modal initialized');
        }

        // Handle submit button clicks - use direct event delegation with highest priority
        // Use capture phase to ensure this runs before other handlers
        function handleSubmitButtonClick(e) {
            // Check if clicked element or parent is a submit button
            const submitBtn = e.target.closest('.submit-requirement-btn');
            if (submitBtn) {
                e.preventDefault();
                e.stopPropagation();
                e.stopImmediatePropagation();
                console.log('Submit button clicked!', submitBtn);
                
                const reqId = submitBtn.dataset.requirementId;
                const fileTypes = submitBtn.dataset.fileTypes || '';
                const maxSize = parseInt(submitBtn.dataset.maxSize || '5242880', 10);

                // Get modal elements
                const modalRequirementId = document.getElementById('modalRequirementId');
                const modalFileHint = document.getElementById('modalFileHint');

                if (!modalRequirementId || !modalFileHint) {
                    console.error('Modal form elements not found');
                    return;
                }

                // Set requirement ID
                modalRequirementId.value = reqId;

                // Update file hint
                const hintSpan = modalFileHint.querySelector('span');
                const hintText = 'Allowed types: ' + fileTypes + ' | Max size: ' + (maxSize / 1024 / 1024).toFixed(2) + ' MB';
                if (hintSpan) {
                    hintSpan.textContent = hintText;
                } else {
                    modalFileHint.innerHTML = '<i class="fas fa-info-circle"></i><span>' + hintText + '</span>';
                }

                // Reset form
                const uploadError = document.getElementById('uploadError');
                if (uploadError) uploadError.style.display = 'none';
                
                const uploadPreview = document.getElementById('uploadFilePreview');
                if (uploadPreview) uploadPreview.style.display = 'none';
                
                const modalFileInput = document.getElementById('modalFile');
                if (modalFileInput) modalFileInput.value = '';
                
                const uploadLabel = document.getElementById('uploadLabel');
                if (uploadLabel) {
                    uploadLabel.classList.remove('file-selected');
                    const title = uploadLabel.querySelector('.file-upload-title');
                    if (title) title.textContent = 'Click to select or drag and drop';
                }
                
                const confirmCheckbox = document.getElementById('modalConfirm');
                if (confirmCheckbox) confirmCheckbox.checked = false;

                // Show modal - ensure it's moved to body and has correct z-index
                if (uploadModal) {
                    console.log('Showing upload modal');
                    
                    // Ensure modal is appended to body (Bootstrap does this, but let's be sure)
                    if (uploadModalEl && uploadModalEl.parentNode !== document.body) {
                        document.body.appendChild(uploadModalEl);
                    }
                    
                    // Show modal
                    uploadModal.show();
                    
                    // After modal is shown, ensure z-index is correct
                    setTimeout(() => {
                        if (uploadModalEl && uploadModalEl.classList.contains('show')) {
                            uploadModalEl.style.zIndex = '1050';
                            const backdrop = document.querySelector('.modal-backdrop');
                            if (backdrop) {
                                backdrop.style.zIndex = '1040';
                            }
                        }
                    }, 10);
                } else {
                    console.error('Upload modal not initialized');
                }
                return; // Exit early after handling submit button
            }

            // Check if clicked element or parent is an attach button
            const attachBtn = e.target.closest('.attach-requirement-btn');
            if (attachBtn) {
                e.preventDefault();
                e.stopPropagation();
                e.stopImmediatePropagation();
                console.log('Attach button clicked!', attachBtn);
                
                const reqId = attachBtn.dataset.requirementId;
                const fileTypes = attachBtn.dataset.fileTypes || '';
                const maxSize = parseInt(attachBtn.dataset.maxSize || '5242880', 10);

                // Get modal elements
                const attachModalRequirementId = document.getElementById('attachModalRequirementId');
                const attachModalFileHint = document.getElementById('attachModalFileHint');

                if (!attachModalRequirementId || !attachModalFileHint) {
                    console.error('Attach modal form elements not found');
                    return;
                }

                // Set requirement ID
                attachModalRequirementId.value = reqId;

                // Update file hint
                const hintSpan = attachModalFileHint.querySelector('span');
                const hintText = 'Allowed types: ' + fileTypes + ' | Max size: ' + (maxSize / 1024 / 1024).toFixed(2) + ' MB';
                if (hintSpan) {
                    hintSpan.textContent = hintText;
                } else {
                    attachModalFileHint.innerHTML = '<i class="fas fa-info-circle"></i><span>' + hintText + '</span>';
                }

                // Reset form
                const attachError = document.getElementById('attachError');
                if (attachError) attachError.style.display = 'none';
                
                const attachPreview = document.getElementById('attachFilePreview');
                if (attachPreview) attachPreview.style.display = 'none';
                
                const attachModalFileInput = document.getElementById('attachModalFile');
                if (attachModalFileInput) attachModalFileInput.value = '';
                
                const attachLabel = document.getElementById('attachLabel');
                if (attachLabel) {
                    attachLabel.classList.remove('file-selected');
                    const title = attachLabel.querySelector('.file-upload-title');
                    if (title) title.textContent = 'Click to select or drag and drop';
                }
                
                const attachConfirmCheckbox = document.getElementById('attachModalConfirm');
                if (attachConfirmCheckbox) attachConfirmCheckbox.checked = false;

                // Show modal - ensure it's moved to body and has correct z-index
                if (attachModal) {
                    console.log('Showing attach modal');
                    const attachModalEl = document.getElementById('attachModal');
                    
                    // Ensure modal is appended to body (Bootstrap does this, but let's be sure)
                    if (attachModalEl && attachModalEl.parentNode !== document.body) {
                        document.body.appendChild(attachModalEl);
                    }
                    
                    // Show modal
                    attachModal.show();
                    
                    // After modal is shown, ensure z-index is correct
                    setTimeout(() => {
                        if (attachModalEl && attachModalEl.classList.contains('show')) {
                            attachModalEl.style.zIndex = '1050';
                            const backdrop = document.querySelector('.modal-backdrop');
                            if (backdrop) {
                                backdrop.style.zIndex = '1040';
                            }
                        }
                    }, 10);
                } else {
                    console.error('Attach modal not initialized');
                }
                return; // Exit early after handling attach button
            }
        }
        
        // Add click handler with capture phase for highest priority
        // This ensures it runs before other handlers that might stop propagation
        document.body.addEventListener('click', handleSubmitButtonClick, true);

        // Setup file preview handlers
        const modalFileInput = document.getElementById('modalFile');
        const uploadFilePreview = document.getElementById('uploadFilePreview');
        if (modalFileInput && uploadFilePreview) {
            modalFileInput.addEventListener('change', function(e) {
                const file = e.target.files[0];
                if (file) {
                    const fileName = uploadFilePreview.querySelector('.file-name');
                    const fileSize = uploadFilePreview.querySelector('.file-size');
                    const label = document.getElementById('uploadLabel');
                    
                    if (fileName) fileName.textContent = file.name;
                    if (fileSize) {
                        const sizeMB = (file.size / 1024 / 1024).toFixed(2);
                        fileSize.textContent = sizeMB + ' MB';
                    }
                    
                    if (label) {
                        label.classList.add('file-selected');
                        const title = label.querySelector('.file-upload-title');
                        if (title) title.textContent = 'File selected: ' + file.name;
                    }
                    
                    uploadFilePreview.style.display = 'block';
                } else {
                    uploadFilePreview.style.display = 'none';
                }
            });
        }

        const attachModalFileInput = document.getElementById('attachModalFile');
        const attachFilePreview = document.getElementById('attachFilePreview');
        if (attachModalFileInput && attachFilePreview) {
            attachModalFileInput.addEventListener('change', function(e) {
                const file = e.target.files[0];
                if (file) {
                    const fileName = attachFilePreview.querySelector('.file-name');
                    const fileSize = attachFilePreview.querySelector('.file-size');
                    const label = document.getElementById('attachLabel');
                    
                    if (fileName) fileName.textContent = file.name;
                    if (fileSize) {
                        const sizeMB = (file.size / 1024 / 1024).toFixed(2);
                        fileSize.textContent = sizeMB + ' MB';
                    }
                    
                    if (label) {
                        label.classList.add('file-selected');
                        const title = label.querySelector('.file-upload-title');
                        if (title) title.textContent = 'File selected: ' + file.name;
                    }
                    
                    attachFilePreview.style.display = 'block';
                } else {
                    attachFilePreview.style.display = 'none';
                }
            });
        }

        // Setup drag and drop
        function setupDragAndDrop(labelId, inputId) {
            const label = document.getElementById(labelId);
            const input = document.getElementById(inputId);
            
            if (!label || !input) return;
            
            ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
                label.addEventListener(eventName, function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                }, false);
            });
            
            ['dragenter', 'dragover'].forEach(eventName => {
                label.addEventListener(eventName, () => {
                    label.classList.add('drag-over');
                }, false);
            });
            
            ['dragleave', 'drop'].forEach(eventName => {
                label.addEventListener(eventName, () => {
                    label.classList.remove('drag-over');
                }, false);
            });
            
            label.addEventListener('drop', (e) => {
                const dt = e.dataTransfer;
                const files = dt.files;
                
                if (files.length > 0) {
                    input.files = files;
                    const event = new Event('change', { bubbles: true });
                    input.dispatchEvent(event);
                }
            }, false);
        }

        if (document.getElementById('uploadLabel')) {
            setupDragAndDrop('uploadLabel', 'modalFile');
        }
        if (document.getElementById('attachLabel')) {
            setupDragAndDrop('attachLabel', 'attachModalFile');
        }

        // Setup form submissions - wait a bit to ensure DOM is ready
        setTimeout(() => {
            const uploadForm = document.getElementById('uploadModalForm');
            if (uploadForm) {
                uploadForm.addEventListener('submit', function(e) {
                e.preventDefault();
                
                // Get elements from within the form or document
                const modalFileInput = uploadForm.querySelector('#modalFile') || document.getElementById('modalFile');
                const modalRequirementId = uploadForm.querySelector('#modalRequirementId') || document.getElementById('modalRequirementId');
                const modalCsrf = uploadForm.querySelector('#modalCsrfToken') || document.getElementById('modalCsrfToken');
                const modalFileHint = uploadForm.querySelector('#modalFileHint') || document.getElementById('modalFileHint');
                
                // Find uploadError - search in modal body or document
                const modalBody = uploadForm.querySelector('.modal-body') || document.querySelector('#uploadModal .modal-body');
                let uploadError = document.getElementById('uploadError');
                
                // If not found, try finding it in modal body
                if (!uploadError && modalBody) {
                    uploadError = modalBody.querySelector('#uploadError');
                }
                
                // If still not found, create it dynamically
                if (!uploadError && modalBody) {
                    const errorDiv = document.createElement('div');
                    errorDiv.id = 'uploadError';
                    errorDiv.className = 'alert alert-danger error-message';
                    errorDiv.style.display = 'none';
                    errorDiv.setAttribute('role', 'alert');
                    errorDiv.innerHTML = '<div class="error-content"><i class="fas fa-exclamation-triangle error-icon"></i><div class="error-details"><strong class="error-title">Upload Error</strong><span class="error-text"></span></div></div>';
                    modalBody.appendChild(errorDiv);
                    uploadError = errorDiv;
                }

                if (!modalFileInput) {
                    console.error('Upload form elements not found', {
                        modalFileInput: !!modalFileInput,
                        uploadError: !!uploadError,
                        form: !!uploadForm,
                        modalBody: !!modalBody
                    });
                    return;
                }

                if (uploadError) uploadError.style.display = 'none';

                const file = modalFileInput.files[0];
                if (!file) {
                    const uploadErrorEl = document.getElementById('uploadError');
                    if (uploadErrorEl) {
                        const errorText = uploadErrorEl.querySelector('.error-text');
                        if (errorText) errorText.textContent = 'Please select a file to upload.';
                        uploadErrorEl.style.display = 'flex';
                    } else {
                        alert('Please select a file to upload.');
                    }
                    return;
                }
                
                // Validate confirmation checkbox
                const confirmCheckbox = document.getElementById('modalConfirm');
                if (!confirmCheckbox || !confirmCheckbox.checked) {
                    const uploadErrorEl = document.getElementById('uploadError');
                    if (uploadErrorEl) {
                        const errorText = uploadErrorEl.querySelector('.error-text');
                        if (errorText) errorText.textContent = 'Please confirm that the uploaded file is accurate and complete.';
                        uploadErrorEl.style.display = 'flex';
                    } else {
                        alert('Please confirm that the uploaded file is accurate and complete.');
                    }
                    return;
                }

                // Validate file size
                const hint = modalFileHint ? (modalFileHint.textContent || '') : '';
                const match = hint.match(/Max size:\s*([0-9\.]+)\s*MB/);
                    if (match) {
                        const maxMB = parseFloat(match[1]);
                        if (file.size > maxMB * 1024 * 1024) {
                            const uploadErrorEl = document.getElementById('uploadError');
                            if (uploadErrorEl) {
                                const errorText = uploadErrorEl.querySelector('.error-text');
                                if (errorText) errorText.textContent = 'File size exceeds the maximum allowed size (' + maxMB + ' MB).';
                                uploadErrorEl.style.display = 'flex';
                            } else {
                                alert('File size exceeds the maximum allowed size (' + maxMB + ' MB).');
                            }
                            return;
                        }
                    }

                // Prepare form data
                const formData = new FormData();
                formData.append('action', 'submit');
                if (modalRequirementId) formData.append('requirement_id', modalRequirementId.value);
                if (modalCsrf) formData.append('csrf_token', modalCsrf.value);
                formData.append('file', file);

                // Show progress
                const submitBtn = document.getElementById('modalSubmitBtn');
                const progressSection = document.getElementById('uploadProgress');
                
                if (submitBtn) {
                    submitBtn.disabled = true;
                    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Uploading...';
                    submitBtn.classList.add('uploading');
                }
                
                if (progressSection) {
                    progressSection.style.display = 'block';
                    const progressBar = document.getElementById('uploadProgressBar');
                    const progressText = document.getElementById('uploadProgressText');
                    if (progressBar) progressBar.style.width = '0%';
                    if (progressText) progressText.textContent = 'Preparing upload...';
                }

                // Submit
                fetch('requirements.php', {
                    method: 'POST',
                    body: formData
                })
                .then(res => res.text())
                .then(text => {
                    location.reload();
                })
                .catch(err => {
                    console.error('Upload error:', err);
                    // Re-find uploadError in case it was created dynamically
                    const uploadErrorEl = document.getElementById('uploadError');
                    if (uploadErrorEl) {
                        const errorText = uploadErrorEl.querySelector('.error-text');
                        if (errorText) errorText.textContent = 'Upload failed. Please try again.';
                        uploadErrorEl.style.display = 'flex';
                    } else {
                        alert('Upload failed. Please try again.');
                    }
                    
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        submitBtn.innerHTML = '<i class="fas fa-upload"></i> Submit File';
                        submitBtn.classList.remove('uploading');
                    }
                    
                    if (progressSection) progressSection.style.display = 'none';
                });
            });
            } else {
                console.warn('Upload form not found in DOM');
            }
        }, 100);

        setTimeout(() => {
            const attachForm = document.getElementById('attachModalForm');
            if (attachForm) {
            attachForm.addEventListener('submit', function(e) {
                e.preventDefault();
                
                // Get elements from within the form or document
                const attachModalFileInput = attachForm.querySelector('#attachModalFile') || document.getElementById('attachModalFile');
                const attachModalRequirementId = attachForm.querySelector('#attachModalRequirementId') || document.getElementById('attachModalRequirementId');
                const attachModalCsrf = attachForm.querySelector('#attachModalCsrfToken') || document.getElementById('attachModalCsrfToken');
                const attachModalFileHint = attachForm.querySelector('#attachModalFileHint') || document.getElementById('attachModalFileHint');
                
                // Find attachError - search in modal body or document
                const attachModalBody = attachForm.querySelector('.modal-body') || document.querySelector('#attachModal .modal-body');
                let attachError = document.getElementById('attachError');
                
                // If not found, try finding it in modal body
                if (!attachError && attachModalBody) {
                    attachError = attachModalBody.querySelector('#attachError');
                }
                
                // If still not found, create it dynamically
                if (!attachError && attachModalBody) {
                    const errorDiv = document.createElement('div');
                    errorDiv.id = 'attachError';
                    errorDiv.className = 'alert alert-danger error-message';
                    errorDiv.style.display = 'none';
                    errorDiv.setAttribute('role', 'alert');
                    errorDiv.innerHTML = '<div class="error-content"><i class="fas fa-exclamation-triangle error-icon"></i><div class="error-details"><strong class="error-title">Upload Error</strong><span class="error-text"></span></div></div>';
                    attachModalBody.appendChild(errorDiv);
                    attachError = errorDiv;
                }

                if (!attachModalFileInput) {
                    console.error('Attach form elements not found', {
                        attachModalFileInput: !!attachModalFileInput,
                        attachError: !!attachError,
                        form: !!attachForm,
                        attachModalBody: !!attachModalBody
                    });
                    return;
                }

                if (attachError) attachError.style.display = 'none';

                const file = attachModalFileInput.files[0];
                if (!file) {
                    const attachErrorEl = document.getElementById('attachError');
                    if (attachErrorEl) {
                        const errorText = attachErrorEl.querySelector('.error-text');
                        if (errorText) errorText.textContent = 'Please select a file to attach.';
                        attachErrorEl.style.display = 'flex';
                    } else {
                        alert('Please select a file to attach.');
                    }
                    return;
                }
                
                // Validate confirmation checkbox
                const attachConfirmCheckbox = document.getElementById('attachModalConfirm');
                if (!attachConfirmCheckbox || !attachConfirmCheckbox.checked) {
                    const attachErrorEl = document.getElementById('attachError');
                    if (attachErrorEl) {
                        const errorText = attachErrorEl.querySelector('.error-text');
                        if (errorText) errorText.textContent = 'Please confirm that the attached file is accurate and complete.';
                        attachErrorEl.style.display = 'flex';
                    } else {
                        alert('Please confirm that the attached file is accurate and complete.');
                    }
                    return;
                }

                // Validate file size
                const hint = attachModalFileHint ? (attachModalFileHint.textContent || '') : '';
                const match = hint.match(/Max size:\s*([0-9\.]+)\s*MB/);
                    if (match) {
                        const maxMB = parseFloat(match[1]);
                        if (file.size > maxMB * 1024 * 1024) {
                            const attachErrorEl = document.getElementById('attachError');
                            if (attachErrorEl) {
                                const errorText = attachErrorEl.querySelector('.error-text');
                                if (errorText) errorText.textContent = 'File size exceeds the maximum allowed size (' + maxMB + ' MB).';
                                attachErrorEl.style.display = 'flex';
                            } else {
                                alert('File size exceeds the maximum allowed size (' + maxMB + ' MB).');
                            }
                            return;
                        }
                    }

                // Prepare form data
                const formData = new FormData();
                formData.append('action', 'attach');
                if (attachModalRequirementId) formData.append('requirement_id', attachModalRequirementId.value);
                if (attachModalCsrf) formData.append('csrf_token', attachModalCsrf.value);
                formData.append('file', file);

                // Show progress
                const submitBtn = document.getElementById('attachModalSubmitBtn');
                const progressSection = document.getElementById('attachProgress');
                
                if (submitBtn) {
                    submitBtn.disabled = true;
                    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Uploading...';
                    submitBtn.classList.add('uploading');
                }
                
                if (progressSection) {
                    progressSection.style.display = 'block';
                    const progressBar = document.getElementById('attachProgressBar');
                    const progressText = document.getElementById('attachProgressText');
                    if (progressBar) progressBar.style.width = '0%';
                    if (progressText) progressText.textContent = 'Preparing upload...';
                }

                // Submit
                fetch('requirements.php', {
                    method: 'POST',
                    body: formData
                })
                .then(res => res.text())
                .then(text => {
                    location.reload();
                })
                .catch(err => {
                    console.error('Attach error:', err);
                    // Re-find attachError in case it was created dynamically
                    const attachErrorEl = document.getElementById('attachError');
                    if (attachErrorEl) {
                        const errorText = attachErrorEl.querySelector('.error-text');
                        if (errorText) errorText.textContent = 'Upload failed. Please try again.';
                        attachErrorEl.style.display = 'flex';
                    } else {
                        alert('Upload failed. Please try again.');
                    }
                    
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        submitBtn.innerHTML = '<i class="fas fa-paperclip"></i> Attach File';
                        submitBtn.classList.remove('uploading');
                    }
                    
                    if (progressSection) progressSection.style.display = 'none';
                });
            });
            } else {
                console.warn('Attach form not found in DOM');
            }
        }, 100);

        // Clear file preview function for onclick handlers
        window.clearFilePreview = function(type) {
            const inputId = type === 'upload' ? 'modalFile' : 'attachModalFile';
            const previewId = type === 'upload' ? 'uploadFilePreview' : 'attachFilePreview';
            const labelId = type === 'upload' ? 'uploadLabel' : 'attachLabel';
            
            const fileInput = document.getElementById(inputId);
            const preview = document.getElementById(previewId);
            const label = document.getElementById(labelId);
            
            if (fileInput) fileInput.value = '';
            if (preview) preview.style.display = 'none';
            
            if (label) {
                label.classList.remove('file-selected');
                const title = label.querySelector('.file-upload-title');
                if (title) title.textContent = 'Click to select or drag and drop';
            }
        };

        console.log('Requirements.js: Initialization complete');
    }

    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initRequirements);
    } else {
        // DOM is already ready
        initRequirements();
    }

})();
