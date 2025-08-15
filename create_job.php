<?php
require_once 'config.php';
requireLogin();
requireAdmin();

$error = '';
$success = '';

// Handle form submission
if ($_POST && isset($_POST['create_job']) && verifyCSRFToken($_POST['csrf_token'])) {
    $title = sanitize($_POST['title']);
    $department = sanitize($_POST['department']);
    $location = sanitize($_POST['location']);
    $employment_type = sanitize($_POST['employment_type']);
    $salary_min = !empty($_POST['salary_min']) ? (float) $_POST['salary_min'] : null;
    $salary_max = !empty($_POST['salary_max']) ? (float) $_POST['salary_max'] : null;
    $currency = sanitize($_POST['currency']);
    $description = sanitize($_POST['description']);
    $requirements = sanitize($_POST['requirements']);
    $responsibilities = sanitize($_POST['responsibilities']);
    $benefits = sanitize($_POST['benefits']);
    $application_deadline = !empty($_POST['application_deadline']) ? $_POST['application_deadline'] : null;
    $status = sanitize($_POST['status']);

    // Validation
    $validation_errors = [];

    if (empty($title) || strlen($title) < 3) {
        $validation_errors[] = 'Job title must be at least 3 characters long';
    }

    if (empty($department)) {
        $validation_errors[] = 'Department is required';
    }

    if (empty($location)) {
        $validation_errors[] = 'Location is required';
    }

    if (!in_array($employment_type, ['full_time', 'part_time', 'contract', 'internship'])) {
        $validation_errors[] = 'Invalid employment type';
    }

    if ($salary_min && $salary_max && $salary_min > $salary_max) {
        $validation_errors[] = 'Minimum salary cannot be greater than maximum salary';
    }

    if (empty($description) || strlen($description) < 50) {
        $validation_errors[] = 'Job description must be at least 50 characters long';
    }

    if (empty($requirements) || strlen($requirements) < 20) {
        $validation_errors[] = 'Requirements must be at least 20 characters long';
    }

    if (empty($responsibilities) || strlen($responsibilities) < 20) {
        $validation_errors[] = 'Responsibilities must be at least 20 characters long';
    }

    if (!in_array($status, ['draft', 'active'])) {
        $validation_errors[] = 'Invalid status selected';
    }

    if ($application_deadline && strtotime($application_deadline) <= time()) {
        $validation_errors[] = 'Application deadline must be in the future';
    }

    if ($validation_errors) {
        error_log("POST: " . print_r($_POST, true));
        error_log("Errors: " . print_r($validation_errors, true));
    }

    if (empty($validation_errors)) {
        debugLog("Creating job posting", [
            'title' => $title,
            'department' => $department,
            'location' => $location,
            'employment_type' => $employment_type,
            'user_id' => $_SESSION['user_id'],
            'post_data' => $_POST
        ]);

        try {
            // Start transaction
            $pdo->beginTransaction();
            
            // Check if the table exists and has the expected structure
            $table_check = $pdo->query("DESCRIBE job_postings");
            if (!$table_check) {
                throw new Exception("job_postings table does not exist");
            }
            
            $stmt = $pdo->prepare("
                INSERT INTO job_postings (
                    title, department, location, employment_type, salary_min, salary_max, currency,
                    description, requirements, responsibilities, benefits, application_deadline, status, posted_by, created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            ");
            
            $result = $stmt->execute([
                $title,
                $department,
                $location,
                $employment_type,
                $salary_min,
                $salary_max,
                $currency,
                $description,
                $requirements,
                $responsibilities,
                $benefits,
                $application_deadline,
                $status,
                $_SESSION['user_id']
            ]);
            
            if (!$result) {
                // Get detailed error information
                $errorInfo = $stmt->errorInfo();
                throw new Exception("Insert failed: " . $errorInfo[2]);
            }
            
            // Check if any rows were actually affected
            $rowCount = $stmt->rowCount();
            if ($rowCount === 0) {
                throw new Exception("No rows were inserted");
            }
            
            // Get the inserted job ID
            $jobId = $pdo->lastInsertId();
            if (!$jobId) {
                throw new Exception("Failed to get inserted job ID");
            }
            
            // Verify the data was actually inserted
            $verify_stmt = $pdo->prepare("SELECT COUNT(*) FROM job_postings WHERE id = ?");
            $verify_stmt->execute([$jobId]);
            $count = $verify_stmt->fetchColumn();
            
            if ($count == 0) {
                throw new Exception("Data verification failed - record not found after insert");
            }
            
            // Commit the transaction
            $pdo->commit();
            
            $success = "Job posting created successfully! Job ID: " . $jobId;
            
            // Log success
            error_log("Job posting created successfully. ID: " . $jobId);
            
            // Redirect to admin jobs page with success message
            header('Location: admin_jobs.php?success=' . urlencode('Job posting created successfully'));
            exit();
            
        } catch (Exception $e) {
            // Rollback the transaction
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            
            $error = 'Failed to create job posting: ' . $e->getMessage();
            
            // Log the detailed error
            error_log("Job posting creation failed: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            
            // Additional debugging information
            error_log("PDO Error Info: " . print_r($pdo->errorInfo(), true));
            error_log("Statement Error Info: " . (isset($stmt) ? print_r($stmt->errorInfo(), true) : 'Statement not created'));
        }
    } else {
        $error = implode(' | ', $validation_errors);
    }
}

// Get departments for dropdown (from existing employee profiles or predefined list)
try {
    $dept_stmt = $pdo->prepare("SELECT DISTINCT department FROM employee_profiles WHERE department IS NOT NULL ORDER BY department");
    $dept_stmt->execute();
    $existing_departments = $dept_stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    error_log("Failed to fetch departments: " . $e->getMessage());
    $existing_departments = [];
}

// Predefined departments if none exist
$predefined_departments = ['HR', 'IT', 'Finance', 'Marketing', 'Sales', 'Operations', 'Customer Service'];
$departments = !empty($existing_departments) ? $existing_departments : $predefined_departments;
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Job Posting - Admin Panel</title>
    <link rel="stylesheet" href="dashboard.css">
    <style>
        .container {
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
        }

        .form-card {
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }

        .form-header {
            text-align: center;
            margin-bottom: 30px;
        }

        .form-header h1 {
            color: #333;
            margin-bottom: 10px;
        }

        .form-header p {
            color: #666;
            font-size: 16px;
        }

        .form-section {
            margin-bottom: 30px;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 8px;
            border-left: 4px solid #007bff;
        }

        .form-section h3 {
            color: #333;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group.full-width {
            grid-column: 1 / -1;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #333;
        }

        .required {
            color: #dc3545;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 12px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 14px;
            transition: border-color 0.3s ease;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #007bff;
        }

        .form-group textarea {
            min-height: 120px;
            resize: vertical;
        }

        .form-help {
            font-size: 12px;
            color: #666;
            margin-top: 5px;
        }

        .salary-grid {
            display: grid;
            grid-template-columns: 1fr 1fr 100px;
            gap: 15px;
            align-items: end;
        }

        .form-actions {
            display: flex;
            gap: 15px;
            justify-content: center;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #e0e0e0;
        }

        .btn {
            padding: 12px 30px;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            transition: all 0.3s ease;
        }

        .btn-primary {
            background: linear-gradient(45deg, #007bff, #0056b3);
            color: white;
        }

        .btn-secondary {
            background: #6c757d;
            color: white;
        }

        .btn-success {
            background: #28a745;
            color: white;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
        }

        .alert {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .char-counter {
            text-align: right;
            font-size: 12px;
            color: #666;
            margin-top: 5px;
        }

        .preview-section {
            background: #e8f4f8;
            padding: 20px;
            border-radius: 8px;
            margin-top: 20px;
            border: 1px solid #bee5eb;
        }

    </style>
</head>

<body>
    <nav class="navbar">
        <div class="navbar-container">
            <img src="logo.png" alt="Logo" class="logo">
            <div class="navbar-nav">
                <span class="admin-badge">ADMIN</span>
                <a href="dashboard.php" class="nav-link">Dashboard</a>
                <a href="employees_listing.php" class="nav-link">Employees</a>
                <a href="admin_request.php" class="nav-link">Requests</a>
                <a href="admin_jobs.php" class="nav-link">Jobs</a>
                <a href="admin_applications.php" class="nav-link">Applications</a>
                <a href="profile.php" class="nav-link">Profile</a>
                <a href="logout.php" class="nav-link">Logout</a>
            </div>
        </div>
    </nav>

    <div class="container">
        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

       

        <div class="form-card">
            <div class="form-header">
                <h1>🆕 Create New Job Posting</h1>
                <p>Fill out the details below to create a new job opportunity</p>
            </div>

            <form method="POST" action="" id="jobForm">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">

                <!-- Basic Information -->
                <div class="form-section">
                    <h3>📋 Basic Information</h3>

                    <div class="form-grid">
                        <div class="form-group">
                            <label for="title">Job Title <span class="required">*</span></label>
                            <input type="text" id="title" name="title" required
                                value="<?php echo isset($_POST['title']) ? htmlspecialchars($_POST['title']) : ''; ?>"
                            >
                        </div>
                        <div class="form-group">
                            <label for="department">Department <span class="required">*</span></label>
                            <select id="department" name="department" required>
                                <option value="">Select Department</option>
                                <?php foreach ($departments as $dept): ?>
                                    <option value="<?php echo htmlspecialchars($dept); ?>" 
                                        <?php echo (isset($_POST['department']) && $_POST['department'] == $dept) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars(ucfirst($dept)); ?>
                                    </option>
                                <?php endforeach; ?>
                                <option value="other" <?php echo (isset($_POST['department']) && $_POST['department'] == 'other') ? 'selected' : ''; ?>>
                                    Other (specify in description)
                                </option>
                            </select>
                        </div>
                    </div>

                    <div class="form-grid">
                        <div class="form-group">
                            <label for="location">Location <span class="required">*</span></label>
                            <input type="text" id="location" name="location" required
                                value="<?php echo isset($_POST['location']) ? htmlspecialchars($_POST['location']) : ''; ?>"
                               >
                        </div>
                        <div class="form-group">
                            <label for="employment_type">Employment Type <span class="required">*</span></label>
                            <select id="employment_type" name="employment_type" required>
                                <option value="">Select Type</option>
                                <option value="full_time" <?php echo (isset($_POST['employment_type']) && $_POST['employment_type'] == 'full_time') ? 'selected' : ''; ?>>Full Time</option>
                                <option value="part_time" <?php echo (isset($_POST['employment_type']) && $_POST['employment_type'] == 'part_time') ? 'selected' : ''; ?>>Part Time</option>
                                <option value="contract" <?php echo (isset($_POST['employment_type']) && $_POST['employment_type'] == 'contract') ? 'selected' : ''; ?>>Contract</option>
                                <option value="internship" <?php echo (isset($_POST['employment_type']) && $_POST['employment_type'] == 'internship') ? 'selected' : ''; ?>>Internship</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Compensation -->
                <div class="form-section">
                    <h3>💰 Compensation</h3>

                    <div class="salary-grid">
                        <div class="form-group">
                            <label for="salary_min">Minimum Salary</label>
                            <input type="number" id="salary_min" name="salary_min" min="0" 
                                value="<?php echo isset($_POST['salary_min']) ? htmlspecialchars($_POST['salary_min']) : ''; ?>"
                                >
                            <div class="form-help">Leave empty if salary is negotiable</div>
                        </div>
                        <div class="form-group">
                            <label for="salary_max">Maximum Salary</label>
                            <input type="number" id="salary_max" name="salary_max" min="0" 
                                value="<?php echo isset($_POST['salary_max']) ? htmlspecialchars($_POST['salary_max']) : ''; ?>"
                                >
                        </div>
                        <div class="form-group">
                            <label for="currency">Currency</label>
                            <select id="currency" name="currency">
                                <option value="USD" <?php echo (isset($_POST['currency']) && $_POST['currency'] == 'USD') ? 'selected' : ''; ?>>USD</option>
                                <option value="EUR" <?php echo (isset($_POST['currency']) && $_POST['currency'] == 'EUR') ? 'selected' : ''; ?>>EUR</option>
                                <option value="TND" <?php echo (!isset($_POST['currency']) || $_POST['currency'] == 'TND') ? 'selected' : ''; ?>>TND</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Job Details -->
                <div class="form-section">
                    <h3>📝 Job Details</h3>

                    <div class="form-group full-width">
                        <label for="description">Job Description <span class="required">*</span></label>
                        <textarea id="description" name="description" required
                            placeholder="Provide a comprehensive description of the role, company culture, and what makes this opportunity exciting..."><?php echo isset($_POST['description']) ? htmlspecialchars($_POST['description']) : ''; ?></textarea>
                        <div id="desc-counter" class="char-counter">0 characters (minimum 50)</div>
                    </div>

                    <div class="form-group full-width">
                        <label for="responsibilities">Key Responsibilities <span class="required">*</span></label>
                        <textarea id="responsibilities" name="responsibilities" required
                            placeholder="List the main responsibilities and duties for this position..."><?php echo isset($_POST['responsibilities']) ? htmlspecialchars($_POST['responsibilities']) : ''; ?></textarea>
                        <div id="resp-counter" class="char-counter">0 characters (minimum 20)</div>
                    </div>

                    <div class="form-group full-width">
                        <label for="requirements">Requirements & Qualifications <span class="required">*</span></label>
                        <textarea id="requirements" name="requirements" required
                            placeholder="List the required skills, experience, education, and qualifications..."><?php echo isset($_POST['requirements']) ? htmlspecialchars($_POST['requirements']) : ''; ?></textarea>
                        <div id="req-counter" class="char-counter">0 characters (minimum 20)</div>
                    </div>

                    <div class="form-group full-width">
                        <label for="benefits">Benefits & Perks</label>
                        <textarea id="benefits" name="benefits"
                            placeholder="Describe the benefits package, perks, and what makes working here great..."><?php echo isset($_POST['benefits']) ? htmlspecialchars($_POST['benefits']) : ''; ?></textarea>
                        <div class="form-help">Optional: Health insurance, flexible hours, remote work, etc.</div>
                    </div>
                </div>

                <!-- Publishing Options -->
                <div class="form-section">
                    <h3>🚀 Publishing Options</h3>

                    <div class="form-grid">
                        <div class="form-group">
                            <label for="application_deadline">Application Deadline</label>
                            <input type="date" id="application_deadline" name="application_deadline"
                                value="<?php echo isset($_POST['application_deadline']) ? htmlspecialchars($_POST['application_deadline']) : ''; ?>"
                                min="<?php echo date('Y-m-d', strtotime('+1 day')); ?>">
                            <div class="form-help">Leave empty for no deadline</div>
                        </div>
                        <div class="form-group">
                            <label for="status">Publication Status <span class="required">*</span></label>
                            <select id="status" name="status" required>
                                <option value="draft" <?php echo (!isset($_POST['status']) || $_POST['status'] == 'draft') ? 'selected' : ''; ?>>Draft (not visible to public)</option>
                                <option value="active" <?php echo (isset($_POST['status']) && $_POST['status'] == 'active') ? 'selected' : ''; ?>>Active (visible to applicants)</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Preview Section -->
                <div class="preview-section" id="preview" style="display: none;">
                    <h3>👁️ Preview</h3>
                    <div id="preview-content"></div>
                </div>

                <!-- Form Actions -->
                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" onclick="showPreview()">👁️ Preview</button>
                    <button type="submit" name="create_job" class="btn btn-primary" id="submit-btn">
                        🆕 Create Job Posting
                    </button>
                    <a href="admin_jobs.php" class="btn btn-secondary">❌ Cancel</a>
                    
                </div>
            </form>
        </div>
    </div>

    <script>
        // Character counters
        function setupCharCounter(textareaId, counterId, minLength) {
            const textarea = document.getElementById(textareaId);
            const counter = document.getElementById(counterId);

            function updateCounter() {
                const length = textarea.value.length;
                counter.textContent = length + ' characters (minimum ' + minLength + ')';
                counter.style.color = length >= minLength ? '#28a745' : '#dc3545';
                updateSubmitButton();
            }

            textarea.addEventListener('input', updateCounter);
            // Initialize counter on page load
            updateCounter();
        }

        setupCharCounter('description', 'desc-counter', 50);
        setupCharCounter('responsibilities', 'resp-counter', 20);
        setupCharCounter('requirements', 'req-counter', 20);

        // Salary validation
        document.getElementById('salary_min').addEventListener('input', validateSalary);
        document.getElementById('salary_max').addEventListener('input', validateSalary);

        function validateSalary() {
            const minSalary = parseFloat(document.getElementById('salary_min').value) || 0;
            const maxSalary = parseFloat(document.getElementById('salary_max').value) || 0;

            if (minSalary > 0 && maxSalary > 0 && minSalary > maxSalary) {
                document.getElementById('salary_max').style.borderColor = '#dc3545';
                document.getElementById('salary_min').style.borderColor = '#dc3545';
            } else {
                document.getElementById('salary_max').style.borderColor = '#e0e0e0';
                document.getElementById('salary_min').style.borderColor = '#e0e0e0';
            }
        }

        // Form validation
        function updateSubmitButton() {
            const title = document.getElementById('title').value.trim();
            const department = document.getElementById('department').value;
            const location = document.getElementById('location').value.trim();
            const employmentType = document.getElementById('employment_type').value;
            const description = document.getElementById('description').value.trim();
            const requirements = document.getElementById('requirements').value.trim();
            const responsibilities = document.getElementById('responsibilities').value.trim();
            const status = document.getElementById('status').value;

            console.log('Validation check:', {
                title: title.length,
                department: department,
                location: location,
                employmentType: employmentType,
                description: description.length,
                requirements: requirements.length,
                responsibilities: responsibilities.length,
                status: status
            });

            const isValid = title.length >= 3 &&
                department &&
                location &&
                employmentType &&
                description.length >= 50 &&
                requirements.length >= 20 &&
                responsibilities.length >= 20 &&
                status;

            const submitBtn = document.getElementById('submit-btn');
            
            console.log('Form is valid:', isValid);
            
            if (isValid) {
                submitBtn.disabled = false;
                submitBtn.style.opacity = '1';
                submitBtn.style.cursor = 'pointer';
            } else {
                submitBtn.disabled = true;
                submitBtn.style.opacity = '0.6';
                submitBtn.style.cursor = 'not-allowed';
            }
        }

        // Add event listeners for validation
        ['title', 'department', 'location', 'employment_type', 'status'].forEach(id => {
            document.getElementById(id).addEventListener('input', updateSubmitButton);
            document.getElementById(id).addEventListener('change', updateSubmitButton);
        });

        // Preview functionality
        function showPreview() {
            const preview = document.getElementById('preview');
            const content = document.getElementById('preview-content');

            const title = document.getElementById('title').value || 'Job Title';
            const department = document.getElementById('department').value || 'Department';
            const location = document.getElementById('location').value || 'Location';
            const employmentType = document.getElementById('employment_type').value || 'full_time';
            const description = document.getElementById('description').value || 'Job description...';
            const requirements = document.getElementById('requirements').value || 'Requirements...';
            const responsibilities = document.getElementById('responsibilities').value || 'Responsibilities...';
            const benefits = document.getElementById('benefits').value || '';
            const salaryMin = document.getElementById('salary_min').value;
            const salaryMax = document.getElementById('salary_max').value;
            const currency = document.getElementById('currency').value;

            let salaryRange = '';
            if (salaryMin && salaryMax) {
                salaryRange = `<p><strong>💰 Salary:</strong> ${parseInt(salaryMin).toLocaleString()} - ${parseInt(salaryMax).toLocaleString()} ${currency}</p>`;
            }

            content.innerHTML = `
                <div style="border: 1px solid #ddd; border-radius: 8px; padding: 20px; background: white;">
                    <h2>${title}</h2>
                    <p><strong>🏢 Department:</strong> ${department}</p>
                    <p><strong>📍 Location:</strong> ${location}</p>
                    <p><strong>💼 Type:</strong> ${employmentType.replace('_', ' ')}</p>
                    ${salaryRange}
                    
                    <h3>Job Description</h3>
                    <p>${description.replace(/\n/g, '<br>')}</p>
                    
                    <h3>Key Responsibilities</h3>
                    <p>${responsibilities.replace(/\n/g, '<br>')}</p>
                    
                    <h3>Requirements</h3>
                    <p>${requirements.replace(/\n/g, '<br>')}</p>
                    
                    ${benefits ? `<h3>Benefits</h3><p>${benefits.replace(/\n/g, '<br>')}</p>` : ''}
                </div>
            `;

            preview.style.display = 'block';
            preview.scrollIntoView({ behavior: 'smooth' });
        }

        // Form submission confirmation
        document.getElementById('jobForm').addEventListener('submit', function (e) {
            console.log('Form submission triggered');
            
            // Check if submit button is disabled
            const submitBtn = document.getElementById('submit-btn');
            if (submitBtn.disabled) {
                console.log('Form submission blocked - button is disabled');
                e.preventDefault();
                return false;
            }
            
            const status = document.getElementById('status').value;

            if (status === 'active') {
                if (!confirm('This will publish the job posting and make it visible to the public. Are you sure?')) {
                    e.preventDefault();
                    return false;
                }
            }

            // Show loading state but don't disable until form is actually submitting
            console.log('Form validation passed, submitting...');
            submitBtn.textContent = '⏳ Creating Job...';
            
            // Allow form to submit
            return true;
        });


        // Initialize form validation
        updateSubmitButton();
        
        
    </script>
</body>

</html>