<!-- File Preview Modal Component -->
<div class="modal fade" id="filePreviewModal" tabindex="-1" aria-labelledby="filePreviewModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="filePreviewModalLabel">
                    <i class="fas fa-file-alt me-2"></i>
                    <span id="previewFileName">File Preview</span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" style="min-height: 500px; max-height: 80vh; overflow: auto;">
                <div id="previewLoader" class="text-center py-5">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="mt-3 text-muted">Loading preview...</p>
                </div>
                
                <div id="previewContent" style="display: none;">
                    <!-- PDF Preview -->
                    <iframe id="pdfPreview" style="width: 100%; height: 70vh; border: none; display: none;"></iframe>
                    
                    <!-- Image Preview -->
                    <div id="imagePreview" class="text-center" style="display: none;">
                        <img id="imagePreviewImg" src="" alt="Preview" style="max-width: 100%; height: auto; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
                    </div>
                    
                    <!-- Unsupported Preview -->
                    <div id="unsupportedPreview" class="text-center py-5" style="display: none;">
                        <i class="fas fa-file-download fa-4x text-muted mb-3"></i>
                        <h5 class="text-muted">Preview not available</h5>
                        <p class="text-muted mb-3">This file type cannot be previewed directly in the browser.</p>
                        <a id="downloadLink" href="#" class="btn btn-primary" download>
                            <i class="fas fa-download me-2"></i>Download File
                        </a>
                    </div>
                </div>
                
                <div id="previewError" class="text-center py-5" style="display: none;">
                    <i class="fas fa-exclamation-triangle fa-4x text-danger mb-3"></i>
                    <h5 class="text-danger">Error Loading Preview</h5>
                    <p class="text-muted">Unable to load file preview. Please try downloading the file instead.</p>
                </div>
            </div>
            <div class="modal-footer">
                <a id="modalDownloadBtn" href="#" class="btn btn-primary" target="_blank">
                    <i class="fas fa-download me-2"></i>Download
                </a>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
/**
 * File Preview JavaScript
 */
function previewFile(fileId, fileType, fileName) {
    const modal = new bootstrap.Modal(document.getElementById('filePreviewModal'));
    const loader = document.getElementById('previewLoader');
    const content = document.getElementById('previewContent');
    const error = document.getElementById('previewError');
    const fileNameEl = document.getElementById('previewFileName');
    const pdfPreview = document.getElementById('pdfPreview');
    const imagePreview = document.getElementById('imagePreview');
    const imagePreviewImg = document.getElementById('imagePreviewImg');
    const unsupportedPreview = document.getElementById('unsupportedPreview');
    const downloadLink = document.getElementById('downloadLink');
    const modalDownloadBtn = document.getElementById('modalDownloadBtn');
    
    // Reset modal
    loader.style.display = 'block';
    content.style.display = 'none';
    error.style.display = 'none';
    pdfPreview.style.display = 'none';
    imagePreview.style.display = 'none';
    unsupportedPreview.style.display = 'none';
    
    // Set file name
    fileNameEl.textContent = fileName || 'File Preview';
    
    // Build preview URL
    const baseUrl = '<?php echo getBaseUrl(); ?>';
    const previewUrl = `${baseUrl}/includes/file_preview.php?type=${fileType}&id=${fileId}`;
    
    // Set download link
    downloadLink.href = previewUrl;
    modalDownloadBtn.href = previewUrl;
    
    // Show modal
    modal.show();
    
    // Determine file type from extension
    const extension = fileName ? fileName.split('.').pop().toLowerCase() : '';
    
    // Load preview based on file type
    setTimeout(() => {
        loader.style.display = 'none';
        content.style.display = 'block';
        
        if (extension === 'pdf') {
            // PDF Preview
            pdfPreview.src = previewUrl;
            pdfPreview.style.display = 'block';
            
            pdfPreview.onload = function() {
                console.log('PDF loaded successfully');
            };
            
            pdfPreview.onerror = function() {
                content.style.display = 'none';
                error.style.display = 'block';
            };
            
        } else if (['jpg', 'jpeg', 'png', 'gif'].includes(extension)) {
            // Image Preview
            imagePreviewImg.src = previewUrl;
            imagePreview.style.display = 'block';
            
            imagePreviewImg.onload = function() {
                console.log('Image loaded successfully');
            };
            
            imagePreviewImg.onerror = function() {
                content.style.display = 'none';
                error.style.display = 'block';
            };
            
        } else {
            // Unsupported file type - show download option
            unsupportedPreview.style.display = 'block';
        }
    }, 500);
}

// Quick preview button generator
function createPreviewButton(fileId, fileType, fileName) {
    return `
        <button type="button" 
                class="btn btn-sm btn-info" 
                onclick="previewFile(${fileId}, '${fileType}', '${fileName}')"
                title="Preview file">
            <i class="fas fa-eye"></i>
        </button>
    `;
}
</script>

<style>
#filePreviewModal .modal-body {
    background: #f8fafc;
}

#pdfPreview {
    background: white;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}

#imagePreviewImg {
    max-height: 70vh;
    object-fit: contain;
}

@media (max-width: 768px) {
    #filePreviewModal .modal-dialog {
        margin: 0.5rem;
    }
    
    #pdfPreview {
        height: 50vh !important;
    }
}
</style>
