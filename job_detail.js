// Enhanced job_detail.js with better file upload debugging
let formState = {
    isSubmitting: false,
    fileValid: false,
    fileValidationMessage: ''
};

// Show user error/success messages
function showUserError(message, type = 'error') {
    // Remove existing alerts
    const existingAlert = document.querySelector('.dynamic-alert');
    if (existingAlert) {
        existingAlert.remove();
    }

    const alertDiv = document.createElement('div');
    alertDiv.className = `alert alert-${type} dynamic-alert`;
    alertDiv.innerHTML = `
        <strong>${type === 'error' ? '❌ Error:' : type === 'success' ? '✅ Success:' : '⚠️ Warning:'}</strong> ${message}
        <button onclick="this.parentElement.remove()" style="float: right; background: none; border: none; font-size: 18px; cursor: pointer;">×</button>
    `;

    const container = document.querySelector('.container');
    if (container && container.firstChild) {
        container.insertBefore(alertDiv, container.firstChild);
    }

    // Auto-remove success messages
    if (type === 'success') {
        setTimeout(() => {
            if (alertDiv.parentNode) {
                alertDiv.remove();
            }
        }, 10000);
    }

    window.scrollTo({ top: 0, behavior: 'smooth' });
}

// Initialize form
function initializeForm() {
    console.log('Initializing job application form...');
    
    try {
        // Set minimum date to today
        const availabilityDate = document.getElementById('availability_date');
        if (availabilityDate) {
            const today = new Date().toISOString().split('T')[0];
            availabilityDate.setAttribute('min', today);
        }

        // Initialize character count
        updateCharacterCount();

        // Setup event listeners
        setupEventListeners();

        // Initial submit button state check
        updateSubmitButton();

        console.log('Form initialized successfully');
        return true;

    } catch (error) {
        console.error('Form initialization error:', error);
        showUserError('Failed to initialize form: ' + error.message);
        return false;
    }
}

// Setup event listeners
function setupEventListeners() {
    // File upload handler
    const fileInput = document.getElementById('resume');
    if (fileInput) {
        fileInput.addEventListener('change', handleFileUpload);
    }

    // Cover letter character count
    const coverLetter = document.getElementById('cover_letter');
    if (coverLetter) {
        coverLetter.addEventListener('input', updateCharacterCount);
        coverLetter.addEventListener('keyup', updateSubmitButton);
    }

    // Form submission
    const form = document.getElementById('applicationForm');
    if (form) {
        form.addEventListener('submit', handleFormSubmission);
    }

    // Real-time validation on form fields
    const formInputs = form.querySelectorAll('input[required], textarea[required]');
    formInputs.forEach(input => {
        input.addEventListener('blur', updateSubmitButton);
        input.addEventListener('input', updateSubmitButton);
    });
}

// Handle file upload
function handleFileUpload() {
    const fileInput = document.getElementById('resume');
    const fileNameDiv = document.getElementById('file-name');
    const label = document.querySelector('.file-upload-label');

    // Reset state
    formState.fileValid = false;
    formState.fileValidationMessage = '';

    try {
        if (!fileInput || !fileNameDiv || !label) {
            console.error('File upload elements not found');
            updateSubmitButton();
            return;
        }

        if (fileInput.files.length === 0) {
            fileNameDiv.textContent = 'No file selected';
            fileNameDiv.style.color = '#666';
            label.style.borderColor = '#ccc';
            label.style.backgroundColor = '#f8f9fa';
            formState.fileValid = false;
            updateSubmitButton();
            return;
        }

        const file = fileInput.files[0];
        console.log('File selected:', {
            name: file.name,
            size: file.size,
            type: file.type,
            lastModified: new Date(file.lastModified)
        });

        const validationResult = validateFile(file);
        
        formState.fileValid = validationResult.isValid;
        formState.fileValidationMessage = validationResult.message;

        if (validationResult.isValid) {
            fileNameDiv.innerHTML = `✅ <strong>Selected:</strong> ${file.name} (${formatFileSize(file.size)})`;
            fileNameDiv.style.color = '#28a745';
            label.style.borderColor = '#28a745';
            label.style.backgroundColor = '#e8f5e8';
            console.log('File validation passed');
        } else {
            fileNameDiv.innerHTML = `❌ <strong>Error:</strong> ${validationResult.message}`;
            fileNameDiv.style.color = '#dc3545';
            label.style.borderColor = '#dc3545';
            label.style.backgroundColor = '#f8d7da';
            console.log('File validation failed:', validationResult.message);
        }

        updateSubmitButton();

    } catch (error) {
        console.error('File upload handling error:', error);
        formState.fileValid = false;
        formState.fileValidationMessage = 'File upload error: ' + error.message;
        updateSubmitButton();
    }
}

// Validate uploaded file
function validateFile(file) {
    const result = {
        isValid: false,
        message: ''
    };

    try {
        if (!file) {
            result.message = 'No file provided';
            return result;
        }

        if (file.size === 0) {
            result.message = 'File is empty';
            return result;
        }

        // File size validation (5MB max)
        const maxSize = 5 * 1024 * 1024;
        if (file.size > maxSize) {
            result.message = `File too large (${formatFileSize(file.size)}). Maximum size is 5MB`;
            return result;
        }

        // File type validation
        const allowedTypes = [
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
        ];
        const allowedExtensions = ['pdf', 'doc', 'docx'];
        
        const fileExtension = file.name.split('.').pop().toLowerCase();
        const typeValid = allowedTypes.includes(file.type) || allowedExtensions.includes(fileExtension);

        console.log('File type validation:', {
            fileName: file.name,
            fileType: file.type,
            fileExtension: fileExtension,
            typeValid: typeValid
        });

        if (!typeValid) {
            result.message = `Invalid file type. Only PDF, DOC, and DOCX files are allowed. Detected: ${file.type || 'unknown'}`;
            return result;
        }

        result.isValid = true;
        result.message = 'File is valid';
        return result;

    } catch (error) {
        result.message = 'File validation error: ' + error.message;
        return result;
    }
}

// Format file size for display
function formatFileSize(bytes) {
    if (bytes === 0) return '0 Bytes';
    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
}

// Update character count for cover letter
function updateCharacterCount() {
    const coverLetter = document.getElementById('cover_letter');
    const charCount = document.getElementById('char-count');
    
    if (coverLetter && charCount) {
        const length = coverLetter.value.length;
        const minLength = 50;
        
        charCount.textContent = `${length} characters (minimum ${minLength})`;
        
        if (length < minLength) {
            charCount.style.color = '#dc3545';
        } else {
            charCount.style.color = '#28a745';
        }
    }
}

// Validate email format
function isValidEmail(email) {
    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    return emailRegex.test(email);
}

// Perform client-side validation
function performClientSideValidation() {
    const result = {
        success: false,
        errors: []
    };

    try {
        // Check required fields
        const requiredFields = [
            { id: 'applicant_name', name: 'Full Name' },
            { id: 'applicant_email', name: 'Email Address' },
            { id: 'cover_letter', name: 'Cover Letter' }
        ];

        requiredFields.forEach(field => {
            const element = document.getElementById(field.id);
            if (!element) {
                result.errors.push(`${field.name} field not found`);
                return;
            }

            const value = element.value.trim();
            if (!value) {
                result.errors.push(`${field.name} is required`);
            }
        });

        // Email validation
        const emailField = document.getElementById('applicant_email');
        if (emailField && emailField.value.trim()) {
            if (!isValidEmail(emailField.value.trim())) {
                result.errors.push('Please enter a valid email address');
            }
        }

        // Cover letter length validation
        const coverLetterField = document.getElementById('cover_letter');
        if (coverLetterField && coverLetterField.value.trim()) {
            if (coverLetterField.value.trim().length < 50) {
                result.errors.push('Cover letter must be at least 50 characters long');
            }
        }

        // File validation
        const resumeInput = document.getElementById('resume');
        if (!resumeInput) {
            result.errors.push('Resume upload field not found');
        } else if (resumeInput.files.length === 0) {
            result.errors.push('Please select a resume file to upload');
        } else if (!formState.fileValid) {
            result.errors.push(formState.fileValidationMessage || 'Invalid resume file');
        }

        result.success = result.errors.length === 0;
        return result;

    } catch (error) {
        console.error('Client-side validation error:', error);
        result.errors.push('Validation system error');
        return result;
    }
}

// Update submit button state
function updateSubmitButton() {
    try {
        const submitBtn = document.getElementById('submit-btn');
        if (!submitBtn) return;

        const validationResult = performClientSideValidation();
        let canSubmit = validationResult.success && !formState.isSubmitting;
        let buttonText = '🚀 Submit Application';
        let buttonColor = '#007bff';

        if (formState.isSubmitting) {
            canSubmit = false;
            buttonText = '📤 Submitting... Please wait';
            buttonColor = '#6c757d';
        } else if (!validationResult.success) {
            canSubmit = false;
            if (validationResult.errors.some(e => e.includes('name'))) {
                buttonText = '❌ Name required';
            } else if (validationResult.errors.some(e => e.includes('email'))) {
                buttonText = '❌ Valid email required';
            } else if (validationResult.errors.some(e => e.includes('cover letter') || e.includes('Cover Letter'))) {
                buttonText = '✍️ Cover letter required (min 50 chars)';
            } else if (validationResult.errors.some(e => e.includes('resume') || e.includes('file'))) {
                buttonText = '📄 Resume file required';
            } else {
                buttonText = '❌ Please fix form errors';
            }
            buttonColor = '#dc3545';
        }

        submitBtn.disabled = !canSubmit;
        submitBtn.textContent = buttonText;
        submitBtn.style.backgroundColor = buttonColor;
        submitBtn.style.cursor = canSubmit ? 'pointer' : 'not-allowed';
        submitBtn.style.opacity = canSubmit ? '1' : '0.7';

        // Add tooltip for disabled button
        if (!canSubmit && validationResult.errors.length > 0) {
            submitBtn.title = 'Issues to fix:\n' + validationResult.errors.join('\n');
        } else {
            submitBtn.title = '';
        }

    } catch (error) {
        console.error('Error updating submit button:', error);
    }
}

// Handle form submission
function handleFormSubmission(event) {
    console.log('Form submission started...');
    
    try {
        // Prevent double submission
        if (formState.isSubmitting) {
            event.preventDefault();
            console.log('Already submitting, preventing duplicate submission');
            showUserError('Please wait, your application is being submitted...', 'warning');
            return false;
        }

        // Perform final validation
        const validationResult = performClientSideValidation();
        if (!validationResult.success) {
            event.preventDefault();
            const errorList = validationResult.errors.join('\n• ');
            showUserError(`Please fix the following issues:\n\n• ${errorList}`, 'error');
            console.log('Client-side validation failed:', validationResult.errors);
            return false;
        }

        // Check network connectivity
        if (navigator.onLine === false) {
            event.preventDefault();
            showUserError('No internet connection detected. Please check your connection and try again.', 'error');
            return false;
        }

        // Set submitting state
        formState.isSubmitting = true;
        updateSubmitButton();

        // Show loading message
        showUserError('Submitting your application, please wait...', 'warning');

        // Log form submission for debugging
        console.log('Form validation passed, submitting form...');
        console.log('Form data being submitted:', {
            name: document.getElementById('applicant_name')?.value,
            email: document.getElementById('applicant_email')?.value,
            fileSelected: document.getElementById('resume')?.files?.length > 0,
            fileName: document.getElementById('resume')?.files?.[0]?.name,
            fileSize: document.getElementById('resume')?.files?.[0]?.size
        });
        
        // Form will submit naturally since we're not preventing default
        return true;

    } catch (error) {
        event.preventDefault();
        formState.isSubmitting = false;
        updateSubmitButton();
        console.error('Form submission error:', error);
        showUserError('An error occurred during submission: ' + error.message, 'error');
        return false;
    }
}

// FIXED: Enhanced fill test data function with file upload instructions
function fillTestData() {
    try {
        document.getElementById('applicant_name').value = 'John Doe';
        document.getElementById('applicant_email').value = 'john.doe@example.com';
        document.getElementById('applicant_phone').value = '+1234567890';
        document.getElementById('cover_letter').value = 'I am very interested in this position and believe I would be a great fit for your team. I have the necessary skills and experience to contribute effectively to your organization. Thank you for considering my application.';
        document.getElementById('years_experience').value = '5';
        document.getElementById('expected_salary').value = '50000';
        
        const today = new Date();
        today.setDate(today.getDate() + 30); // 30 days from now
        document.getElementById('availability_date').value = today.toISOString().split('T')[0];
        
        updateCharacterCount();
        updateSubmitButton();
        
        // Check if file is already uploaded
        const fileInput = document.getElementById('resume');
        if (fileInput && fileInput.files.length > 0) {
            showUserError('Test data filled successfully! Resume file already selected: ' + fileInput.files[0].name, 'success');
        } else {
            showUserError('Test data filled successfully! ⚠️ IMPORTANT: You still need to manually select a resume file using the file upload button above.', 'warning');
            
            // Highlight the file upload area
            const uploadLabel = document.querySelector('.file-upload-label');
            if (uploadLabel) {
                uploadLabel.style.border = '3px solid #ffc107';
                uploadLabel.style.backgroundColor = '#fff3cd';
                uploadLabel.innerHTML = '📄 ⚠️ CLICK HERE to upload your resume (PDF, DOC, DOCX - Max 5MB) ⚠️';
                
                // Remove highlight after 10 seconds
                setTimeout(() => {
                    uploadLabel.style.border = '';
                    uploadLabel.style.backgroundColor = '';
                    uploadLabel.innerHTML = '📄 Click to upload your resume (PDF, DOC, DOCX - Max 5MB)';
                }, 10000);
            }
        }
    } catch (error) {
        showUserError('Error filling test data: ' + error.message, 'error');
    }
}

// Enhanced debug function
function debugApplicationForm() {
    const fileInput = document.getElementById('resume');
    const debugData = {
        formState: formState,
        validation: performClientSideValidation(),
        fileInfo: {
            inputExists: !!fileInput,
            filesSelected: fileInput?.files?.length || 0,
            fileDetails: fileInput?.files?.length > 0 ? {
                name: fileInput.files[0].name,
                size: fileInput.files[0].size,
                type: fileInput.files[0].type,
                lastModified: new Date(fileInput.files[0].lastModified).toISOString()
            } : null
        },
        elements: {}
    };

    const elementIds = ['applicant_name', 'applicant_email', 'applicant_phone', 'cover_letter', 'resume'];
    elementIds.forEach(id => {
        const element = document.getElementById(id);
        debugData.elements[id] = {
            exists: !!element,
            value: element && element.type !== 'file' ? element.value : 'N/A for file input',
            required: element ? element.hasAttribute('required') : false
        };
    });

    console.log('Debug Data:', debugData);
    
    let debugMessage = `Form Debug Info:\n\n`;
    debugMessage += `File Input Found: ${debugData.fileInfo.inputExists}\n`;
    debugMessage += `Files Selected: ${debugData.fileInfo.filesSelected}\n`;
    if (debugData.fileInfo.fileDetails) {
        debugMessage += `File Name: ${debugData.fileInfo.fileDetails.name}\n`;
        debugMessage += `File Size: ${formatFileSize(debugData.fileInfo.fileDetails.size)}\n`;
        debugMessage += `File Type: ${debugData.fileInfo.fileDetails.type}\n`;
    }
    debugMessage += `File Valid: ${formState.fileValid}\n`;
    debugMessage += `Validation Errors: ${debugData.validation.errors.length}\n`;
    if (debugData.validation.errors.length > 0) {
        debugMessage += `Errors: ${debugData.validation.errors.join(', ')}\n`;
    }
    debugMessage += `Form Can Submit: ${debugData.validation.success}\n`;
    
    alert(debugMessage);
}

function resetFormState() {
    formState = {
        isSubmitting: false,
        fileValid: false,
        fileValidationMessage: ''
    };
    
    updateSubmitButton();
    showUserError('Form state reset successfully', 'success');
}

// Enhanced file validation test
function testFileValidation() {
    const fileInput = document.getElementById('resume');
    if (!fileInput) {
        alert('❌ Resume file input not found in the DOM!');
        return;
    }
    
    console.log('File input element:', fileInput);
    console.log('File input files property:', fileInput.files);
    
    if (fileInput.files.length === 0) {
        alert('❌ No file selected. Please click the file upload button and select a PDF, DOC, or DOCX file first.');
        return;
    }
    
    const file = fileInput.files[0];
    const validation = validateFile(file);
    
    const details = `File Validation Results:

📁 File Details:
• Name: ${file.name}
• Size: ${formatFileSize(file.size)}
• Type: ${file.type || 'Not detected'}
• Extension: ${file.name.split('.').pop().toLowerCase()}
• Last Modified: ${new Date(file.lastModified).toLocaleString()}

✅ Validation Results:
• Valid: ${validation.isValid ? 'YES' : 'NO'}
• Message: ${validation.message}
• Form State Valid: ${formState.fileValid}

🔧 Technical Info:
• File object type: ${typeof file}
• File constructor: ${file.constructor.name}
• Has File API support: ${window.File ? 'YES' : 'NO'}`;
    
    alert(details);
}

// Initialize when DOM is ready
document.addEventListener('DOMContentLoaded', function() {
    try {
        if (!initializeForm()) {
            console.error('Form initialization failed');
            showUserError('Form failed to initialize properly. Please refresh the page.', 'error');
        }
    } catch (error) {
        console.error('DOM initialization error:', error);
        showUserError('Page initialization error: ' + error.message, 'error');
    }
});

// Global error handler
window.addEventListener('error', function(event) {
    console.error('Global JavaScript error:', event.error);
    if (formState.isSubmitting) {
        formState.isSubmitting = false;
        updateSubmitButton();
        showUserError('A JavaScript error occurred. Please try again.', 'error');
    }
});

// Network connectivity monitoring
window.addEventListener('online', function() {
    updateSubmitButton();
    showUserError('Internet connection restored.', 'success');
});

window.addEventListener('offline', function() {
    updateSubmitButton();
    showUserError('Internet connection lost. Please check your connection.', 'error');
});