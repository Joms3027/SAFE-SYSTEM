<!-- Help Modal Component -->
<div class="modal fade" id="helpModal" tabindex="-1" aria-labelledby="helpModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="helpModalLabel">
                    <i class="fas fa-question-circle me-2"></i>Help & User Guide
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <!-- Help Navigation Tabs -->
                <ul class="nav nav-tabs mb-4" id="helpTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="getting-started-tab" data-bs-toggle="tab" data-bs-target="#getting-started" type="button" role="tab">
                            <i class="fas fa-rocket me-1"></i>Getting Started
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="faq-tab" data-bs-toggle="tab" data-bs-target="#faq" type="button" role="tab">
                            <i class="fas fa-question me-1"></i>FAQ
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="tutorials-tab" data-bs-toggle="tab" data-bs-target="#tutorials" type="button" role="tab">
                            <i class="fas fa-book me-1"></i>Tutorials
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="contact-tab" data-bs-toggle="tab" data-bs-target="#contact" type="button" role="tab">
                            <i class="fas fa-envelope me-1"></i>Contact Support
                        </button>
                    </li>
                </ul>

                <!-- Tab Content -->
                <div class="tab-content" id="helpTabsContent">
                    <!-- Getting Started Tab -->
                    <div class="tab-pane fade show active" id="getting-started" role="tabpanel">
                        <h5 class="mb-3"><i class="fas fa-rocket me-2 text-primary"></i>Welcome to WPU Faculty System!</h5>
                        
                        <?php if (isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'faculty'): ?>
                            <!-- Faculty Getting Started -->
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle me-2"></i>
                                <strong>Quick Start Guide for Faculty Members</strong>
                            </div>
                            
                            <div class="accordion" id="facultyAccordion">
                                <div class="accordion-item">
                                    <h2 class="accordion-header">
                                        <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#step1">
                                            <strong>Step 1:</strong>&nbsp;Complete Your Personal Data Sheet (PDS)
                                        </button>
                                    </h2>
                                    <div id="step1" class="accordion-collapse collapse show" data-bs-parent="#facultyAccordion">
                                        <div class="accordion-body">
                                            <ul>
                                                <li>Navigate to <strong>PDS</strong> in the sidebar menu</li>
                                                <li>Fill in all required fields with accurate information</li>
                                                <li>Save your progress as you go (auto-save is enabled)</li>
                                                <li>Submit for admin review once complete</li>
                                            </ul>
                                            <div class="alert alert-warning small mb-0">
                                                <i class="fas fa-exclamation-triangle me-1"></i>
                                                Your PDS must be approved before other submissions are processed.
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="accordion-item">
                                    <h2 class="accordion-header">
                                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#step2">
                                            <strong>Step 2:</strong>&nbsp;View Active Requirements
                                        </button>
                                    </h2>
                                    <div id="step2" class="accordion-collapse collapse" data-bs-parent="#facultyAccordion">
                                        <div class="accordion-body">
                                            <ul>
                                                <li>Check the <strong>Requirements</strong> page regularly for new assignments</li>
                                                <li>Note the deadline for each requirement</li>
                                                <li>Download any templates or instruction files provided</li>
                                                <li>Requirements marked "Due Soon" need immediate attention</li>
                                            </ul>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="accordion-item">
                                    <h2 class="accordion-header">
                                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#step3">
                                            <strong>Step 3:</strong>&nbsp;Submit Files
                                        </button>
                                    </h2>
                                    <div id="step3" class="accordion-collapse collapse" data-bs-parent="#facultyAccordion">
                                        <div class="accordion-body">
                                            <ul>
                                                <li>Click <strong>"Submit"</strong> button next to any requirement</li>
                                                <li>Upload files in accepted formats (PDF, DOCX, etc.)</li>
                                                <li>Add comments or notes if needed</li>
                                                <li>Track submission status in <strong>Submissions</strong> page</li>
                                            </ul>
                                            <div class="alert alert-success small mb-0">
                                                <i class="fas fa-check-circle me-1"></i>
                                                You'll receive notifications when your submissions are reviewed.
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php elseif (isset($_SESSION['user_type']) && in_array($_SESSION['user_type'], ['admin', 'super_admin'])): ?>
                            <!-- Admin Getting Started -->
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle me-2"></i>
                                <strong>Quick Start Guide for Administrators</strong>
                            </div>
                            
                            <div class="accordion" id="adminAccordion">
                                <div class="accordion-item">
                                    <h2 class="accordion-header">
                                        <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#admin1">
                                            <strong>Step 1:</strong>&nbsp;Manage Faculty Members
                                        </button>
                                    </h2>
                                    <div id="admin1" class="accordion-collapse collapse show" data-bs-parent="#adminAccordion">
                                        <div class="accordion-body">
                                            <ul>
                                                <li>Navigate to <strong>Faculty</strong> page to view all members</li>
                                                <li>Verify and activate new registrations</li>
                                                <li>Edit faculty profiles as needed</li>
                                                <li>Assign faculty members to departments</li>
                                            </ul>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="accordion-item">
                                    <h2 class="accordion-header">
                                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#admin2">
                                            <strong>Step 2:</strong>&nbsp;Create and Manage Requirements
                                        </button>
                                    </h2>
                                    <div id="admin2" class="accordion-collapse collapse" data-bs-parent="#adminAccordion">
                                        <div class="accordion-body">
                                            <ul>
                                                <li>Go to <strong>Requirements</strong> page</li>
                                                <li>Click <strong>"Create New Requirement"</strong></li>
                                                <li>Set title, description, deadline, and file requirements</li>
                                                <li>Assign to specific faculty members or departments</li>
                                            </ul>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="accordion-item">
                                    <h2 class="accordion-header">
                                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#admin3">
                                            <strong>Step 3:</strong>&nbsp;Review Submissions
                                        </button>
                                    </h2>
                                    <div id="admin3" class="accordion-collapse collapse" data-bs-parent="#adminAccordion">
                                        <div class="accordion-body">
                                            <ul>
                                                <li>Check <strong>Dashboard</strong> for pending items</li>
                                                <li>Review PDS submissions for accuracy</li>
                                                <li>Approve or reject file submissions with feedback</li>
                                                <li>Faculty members receive automatic notifications</li>
                                            </ul>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- FAQ Tab -->
                    <div class="tab-pane fade" id="faq" role="tabpanel">
                        <h5 class="mb-3"><i class="fas fa-question-circle me-2 text-primary"></i>Frequently Asked Questions</h5>
                        
                        <div class="accordion" id="faqAccordion">
                            <div class="accordion-item">
                                <h2 class="accordion-header">
                                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq1">
                                        How do I reset my password?
                                    </button>
                                </h2>
                                <div id="faq1" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                                    <div class="accordion-body">
                                        Currently, password resets must be requested through the system administrator. 
                                        Contact <strong>admin@wpu.edu.ph</strong> with your email address and Safe Employee ID.
                                    </div>
                                </div>
                            </div>
                            
                            <div class="accordion-item">
                                <h2 class="accordion-header">
                                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq2">
                                        What file formats are accepted for submissions?
                                    </button>
                                </h2>
                                <div id="faq2" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                                    <div class="accordion-body">
                                        The system accepts the following file formats:
                                        <ul class="mt-2 mb-0">
                                            <li><strong>Documents:</strong> PDF, DOC, DOCX</li>
                                            <li><strong>Images:</strong> JPG, JPEG, PNG</li>
                                            <li><strong>Spreadsheets:</strong> XLS, XLSX</li>
                                            <li><strong>Maximum file size:</strong> 10MB per file</li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="accordion-item">
                                <h2 class="accordion-header">
                                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq3">
                                        How long does it take to review my submission?
                                    </button>
                                </h2>
                                <div id="faq3" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                                    <div class="accordion-body">
                                        Submissions are typically reviewed within <strong>2-3 business days</strong>. 
                                        You'll receive an email notification when your submission has been approved or if changes are needed.
                                    </div>
                                </div>
                            </div>
                            
                            <div class="accordion-item">
                                <h2 class="accordion-header">
                                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq4">
                                        Can I edit my submission after submitting?
                                    </button>
                                </h2>
                                <div id="faq4" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                                    <div class="accordion-body">
                                        Once submitted, files cannot be edited. If changes are needed:
                                        <ul class="mt-2 mb-0">
                                            <li>Wait for admin feedback if it's rejected</li>
                                            <li>Resubmit with the corrected file</li>
                                            <li>Or contact the administrator to withdraw and resubmit</li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="accordion-item">
                                <h2 class="accordion-header">
                                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq5">
                                        Why can't I see any requirements?
                                    </button>
                                </h2>
                                <div id="faq5" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                                    <div class="accordion-body">
                                        This could be due to:
                                        <ul class="mt-2 mb-0">
                                            <li>Your account hasn't been verified by an administrator yet</li>
                                            <li>No requirements have been assigned to you</li>
                                            <li>Your PDS needs to be completed and approved first</li>
                                        </ul>
                                        Contact your administrator if the issue persists.
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Tutorials Tab -->
                    <div class="tab-pane fade" id="tutorials" role="tabpanel">
                        <h5 class="mb-3"><i class="fas fa-book me-2 text-primary"></i>Video Tutorials & Guides</h5>
                        
                        <div class="alert alert-info">
                            <i class="fas fa-video me-2"></i>
                            Video tutorials coming soon! Check back later for step-by-step video guides.
                        </div>
                        
                        <h6 class="mt-4 mb-3">Written Guides:</h6>
                        <div class="list-group">
                            <a href="#" class="list-group-item list-group-item-action">
                                <div class="d-flex w-100 justify-content-between">
                                    <h6 class="mb-1"><i class="fas fa-file-pdf me-2 text-danger"></i>How to Complete Your PDS</h6>
                                    <small class="text-muted"><i class="fas fa-download"></i></small>
                                </div>
                                <p class="mb-1 small">Step-by-step guide for filling out your Personal Data Sheet correctly.</p>
                            </a>
                            <a href="#" class="list-group-item list-group-item-action">
                                <div class="d-flex w-100 justify-content-between">
                                    <h6 class="mb-1"><i class="fas fa-file-pdf me-2 text-danger"></i>Submitting Requirements</h6>
                                    <small class="text-muted"><i class="fas fa-download"></i></small>
                                </div>
                                <p class="mb-1 small">Learn how to upload and track your requirement submissions.</p>
                            </a>
                            <a href="#" class="list-group-item list-group-item-action">
                                <div class="d-flex w-100 justify-content-between">
                                    <h6 class="mb-1"><i class="fas fa-file-pdf me-2 text-danger"></i>Understanding Notifications</h6>
                                    <small class="text-muted"><i class="fas fa-download"></i></small>
                                </div>
                                <p class="mb-1 small">How to manage and respond to system notifications effectively.</p>
                            </a>
                        </div>
                    </div>

                    <!-- Contact Support Tab -->
                    <div class="tab-pane fade" id="contact" role="tabpanel">
                        <h5 class="mb-3"><i class="fas fa-envelope me-2 text-primary"></i>Contact Support</h5>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <div class="card bg-light">
                                    <div class="card-body">
                                        <h6 class="card-title"><i class="fas fa-envelope me-2 text-primary"></i>Email Support</h6>
                                        <p class="card-text small">For technical issues or account problems:</p>
                                        <a href="mailto:admin@wpu.edu.ph" class="btn btn-sm btn-primary">
                                            <i class="fas fa-envelope me-1"></i>admin@wpu.edu.ph
                                        </a>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6 mb-3">
                                <div class="card bg-light">
                                    <div class="card-body">
                                        <h6 class="card-title"><i class="fas fa-phone me-2 text-success"></i>Phone Support</h6>
                                        <p class="card-text small">Call us during office hours (8AM-5PM):</p>
                                        <a href="tel:+1234567890" class="btn btn-sm btn-success">
                                            <i class="fas fa-phone me-1"></i>(123) 456-7890
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="alert alert-info mt-3">
                            <h6 class="alert-heading"><i class="fas fa-clock me-2"></i>Office Hours</h6>
                            <p class="mb-0 small">
                                <strong>Monday - Friday:</strong> 8:00 AM - 5:00 PM<br>
                                <strong>Saturday:</strong> 9:00 AM - 12:00 PM<br>
                                <strong>Sunday:</strong> Closed
                            </p>
                        </div>
                        
                        <div class="card mt-3">
                            <div class="card-header">
                                <h6 class="mb-0"><i class="fas fa-map-marker-alt me-2"></i>Office Location</h6>
                            </div>
                            <div class="card-body">
                                <p class="mb-2"><strong>WPU Faculty Services Office</strong></p>
                                <p class="mb-0 small">
                                    Western Philippines University<br>
                                    Admin Building, 2nd Floor<br>
                                    Puerto Princesa City, Palawan
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="fas fa-times me-1"></i>Close
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Help Button (Floating) -->
<button type="button" 
        class="btn btn-primary btn-help-float" 
        data-bs-toggle="modal" 
        data-bs-target="#helpModal"
        title="Need Help? Click here">
    <i class="fas fa-question-circle fa-lg"></i>
</button>

<style>
.btn-help-float {
    position: fixed;
    bottom: 30px;
    right: 30px;
    width: 60px;
    height: 60px;
    border-radius: 50%;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
    z-index: 1000;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.3s ease;
}

.btn-help-float:hover {
    transform: scale(1.1);
    box-shadow: 0 6px 16px rgba(0, 0, 0, 0.4);
}

.btn-help-float i {
    font-size: 24px;
}

@media (max-width: 768px) {
    .btn-help-float {
        bottom: 20px;
        right: 20px;
        width: 50px;
        height: 50px;
    }
    
    .btn-help-float i {
        font-size: 20px;
    }
}
</style>
