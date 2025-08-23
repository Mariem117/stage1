<?php
require_once 'config.php';

// Get job ID from URL
$job_id = $_GET['id'] ?? 0;

if (!$job_id) {
    header('Location: jobs.php');
    exit();
}

// Handle form submission
if ($_POST['action'] ?? '' === 'apply') {
    $errors = [];
    $success = false;

    // Validate CSRF token
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid security token. Please try again.';
    } else {
        // Validate form data
        $applicant_name = sanitize($_POST['applicant_name'] ?? '');
        $applicant_email = sanitize($_POST['applicant_email'] ?? '');
        $applicant_phone = sanitize($_POST['applicant_phone'] ?? '');
        $cover_letter = sanitize($_POST['cover_letter'] ?? '');
        $years_experience = (int) ($_POST['years_experience'] ?? 0);
        $expected_salary = floatval($_POST['expected_salary'] ?? 0);
        $availability_date = $_POST['availability_date'] ?? '';

        // Basic validation
        if (empty($applicant_name)) {
            $errors[] = 'Full name is required.';
        }
        if (empty($applicant_email) || !filter_var($applicant_email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Valid email address is required.';
        }
        if (empty($cover_letter)) {
            $errors[] = 'Cover letter is required.';
        }
        if (strlen($cover_letter) < 50) {
            $errors[] = 'Cover letter must be at least 50 characters long.';
        }

        // Check if user already applied for this job
        $existing_stmt = $pdo->prepare("SELECT id FROM job_applications WHERE job_posting_id = ? AND applicant_email = ?");
        $existing_stmt->execute([$job_id, $applicant_email]);
        if ($existing_stmt->fetch()) {
            $errors[] = 'You have already applied for this position.';
        }

        // Handle resume upload
        $resume_path = null;
        if (isset($_FILES['resume']) && $_FILES['resume']['error'] === UPLOAD_ERR_OK) {
            $upload_result = handleFileUpload($_FILES['resume'], 'uploads/resumes/');
            if ($upload_result['success']) {
                $resume_path = $upload_result['path'];
            } else {
                $errors[] = 'Resume upload failed: ' . $upload_result['error'];
            }
        } else if ($_FILES['resume']['error'] !== UPLOAD_ERR_NO_FILE) {
            $errors[] = 'Resume upload is required.';
        }

        // If no errors, save the application
        if (empty($errors)) {
            try {
                // Generate application number
                $application_number = 'APP-' . date('Y') . '-' . str_pad(rand(1, 999999), 6, '0', STR_PAD_LEFT);

                // Make sure application number is unique
                $check_stmt = $pdo->prepare("SELECT id FROM job_applications WHERE application_number = ?");
                while (true) {
                    $check_stmt->execute([$application_number]);
                    if (!$check_stmt->fetch())
                        break;
                    $application_number = 'APP-' . date('Y') . '-' . str_pad(rand(1, 999999), 6, '0', STR_PAD_LEFT);
                }

                $stmt = $pdo->prepare("
                    INSERT INTO job_applications (
                        job_posting_id, applicant_name, applicant_email, applicant_phone,
                        cover_letter, resume_path, years_experience, expected_salary,
                        availability_date, application_number
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");

                $stmt->execute([
                    $job_id,
                    $applicant_name,
                    $applicant_email,
                    $applicant_phone,
                    $cover_letter,
                    $resume_path,
                    $years_experience,
                    $expected_salary > 0 ? $expected_salary : null,
                    !empty($availability_date) ? $availability_date : null,
                    $application_number
                ]);

                $success = true;

                // Create notification for admins
                $job_title_stmt = $pdo->prepare("SELECT title FROM job_postings WHERE id = ?");
                $job_title_stmt->execute([$job_id]);
                $job_title = $job_title_stmt->fetchColumn();

                notifyAllAdmins(
                    $pdo,
                    'job_application',
                    'New Job Application',
                    "New application received for {$job_title} from {$applicant_name}",
                    $job_id
                );

            } catch (Exception $e) {
                error_log("Job application error: " . $e->getMessage());
                $errors[] = 'An error occurred while submitting your application. Please try again.';
            }
        }
    }
}

// Fetch job details
$stmt = $pdo->prepare("
    SELECT jp.*, u.username as posted_by_name
    FROM job_postings jp
    JOIN users u ON jp.posted_by = u.id
    WHERE jp.id = ? AND jp.status = 'active'
");
$stmt->execute([$job_id]);
$job = $stmt->fetch();

if (!$job) {
    header('Location: jobs.php');
    exit();
}

// Check if application deadline has passed
$deadline_passed = $job['application_deadline'] && strtotime($job['application_deadline']) < time();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($job['title']); ?> - Employee Management System</title>
    <link rel="stylesheet" href="job_detail.css">

</head>

<body>
<nav class="navbar">
        <div class="navbar-container">
            <img src="logo.png" alt="Logo" class="logo">
            <div class="navbar-nav">
                <a href="jobs.php" class="nav-link">Jobs</a>
                <a href="login.php" class="nav-link">Login</a>
            </div>
        </div>
    </nav>
    <div class="container">

        <!-- Job Header -->
        <div class="job-header">
            <div class="job-type-badge type-<?php echo $job['employment_type']; ?>">
                <?php echo ucfirst(str_replace('_', ' ', $job['employment_type'])); ?>
            </div>

            <h1 class="job-title"><?php echo htmlspecialchars($job['title']); ?></h1>

            <div class="job-meta-grid">
                <div class="meta-item">
                    <span class="meta-icon">🏢</span>
                    <span><?php echo htmlspecialchars($job['department']); ?></span>
                </div>
                <div class="meta-item">
                    <span class="meta-icon">📍</span>
                    <span><?php echo htmlspecialchars($job['location']); ?></span>
                </div>
                <?php if ($job['salary_min'] && $job['salary_max']): ?>
                    <div class="meta-item">
                        <span class="meta-icon">💰</span>
                        <span><?php echo number_format($job['salary_min']); ?> -
                            <?php echo number_format($job['salary_max']); ?>     <?php echo $job['currency']; ?></span>
                    </div>
                <?php endif; ?>
                <div class="meta-item">
                    <span class="meta-icon">📅</span>
                    <span>Posted <?php echo date('M j, Y', strtotime($job['created_at'])); ?></span>
                </div>
            </div>

            <?php if ($job['application_deadline']): ?>
                <div class="deadline-warning <?php echo $deadline_passed ? 'deadline-passed' : ''; ?>">
                    <strong>
                        <?php if ($deadline_passed): ?>
                            ⚠️ Application deadline has passed
                            (<?php echo date('F j, Y', strtotime($job['application_deadline'])); ?>)
                        <?php else: ?>
                            ⏰ Application deadline: <?php echo date('F j, Y', strtotime($job['application_deadline'])); ?>
                        <?php endif; ?>
                    </strong>
                </div>
            <?php endif; ?>
        </div>

        <div class="job-content">
            <!-- Job Details -->
            <div class="job-details">
                <div class="detail-section">
                    <h3 class="section-title">
                        <span>📋</span> Job Description
                    </h3>
                    <div class="section-content">
                        <?php echo nl2br(htmlspecialchars($job['description'])); ?>
                    </div>
                </div>

                <div class="detail-section">
                    <h3 class="section-title">
                        <span>✅</span> Requirements
                    </h3>
                    <div class="section-content">
                        <?php echo nl2br(htmlspecialchars($job['requirements'])); ?>
                    </div>
                </div>

                <div class="detail-section">
                    <h3 class="section-title">
                        <span>🎯</span> Responsibilities
                    </h3>
                    <div class="section-content">
                        <?php echo nl2br(htmlspecialchars($job['responsibilities'])); ?>
                    </div>
                </div>

                <?php if ($job['benefits']): ?>
                    <div class="detail-section">
                        <h3 class="section-title">
                            <span>🎁</span> Benefits
                        </h3>
                        <div class="section-content">
                            <?php echo nl2br(htmlspecialchars($job['benefits'])); ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Application Form -->
            <div class="application-form">
                <h3 class="form-title">Apply for this Position</h3>

                <?php if (isset($success) && $success): ?>
                    <div class="success-message">
                        <strong>🎉 Application Submitted Successfully!</strong><br>
                        Thank you for your interest. We'll review your application and get back to you soon.
                    </div>
                <?php elseif ($deadline_passed): ?>
                    <div class="error-message">
                        <strong>Application Deadline Passed</strong><br>
                        Unfortunately, the application deadline for this position has passed.
                    </div>
                <?php else: ?>
                    <?php if (!empty($errors)): ?>
                        <div class="error-message">
                            <strong>Please fix the following errors:</strong><br>
                            <?php foreach ($errors as $error): ?>
                                • <?php echo htmlspecialchars($error); ?><br>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="apply">
                        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">

                        <div class="form-group">
                            <label for="applicant_name">Full Name <span class="required">*</span></label>
                            <input type="text" id="applicant_name" name="applicant_name"
                                value="<?php echo htmlspecialchars($_POST['applicant_name'] ?? ''); ?>" required>
                        </div>

                        <div class="form-group">
                            <label for="applicant_email">Email Address <span class="required">*</span></label>
                            <input type="email" id="applicant_email" name="applicant_email"
                                value="<?php echo htmlspecialchars($_POST['applicant_email'] ?? ''); ?>" required>
                        </div>

                        <div class="form-group">
                            <label for="applicant_phone">Phone Number</label>
                            <input type="tel" id="applicant_phone" name="applicant_phone"
                                value="<?php echo htmlspecialchars($_POST['applicant_phone'] ?? ''); ?>">
                        </div>

                        <div class="form-group">
                            <label for="years_experience">Years of Experience</label>
                            <select id="years_experience" name="years_experience">
                                <option value="0" <?php echo ($_POST['years_experience'] ?? '') == '0' ? 'selected' : ''; ?>>
                                    0-1 years</option>
                                <option value="2" <?php echo ($_POST['years_experience'] ?? '') == '2' ? 'selected' : ''; ?>>
                                    2-3 years</option>
                                <option value="4" <?php echo ($_POST['years_experience'] ?? '') == '4' ? 'selected' : ''; ?>>
                                    4-5 years</option>
                                <option value="6" <?php echo ($_POST['years_experience'] ?? '') == '6' ? 'selected' : ''; ?>>
                                    6-10 years</option>
                                <option value="11" <?php echo ($_POST['years_experience'] ?? '') == '11' ? 'selected' : ''; ?>>10+ years</option>
                            </select>
                        </div>

                        <?php if ($job['salary_min'] || $job['salary_max']): ?>
                            <div class="form-group">
                                <label for="expected_salary">Expected Salary (<?php echo $job['currency']; ?>)</label>
                                <input type="number" id="expected_salary" name="expected_salary"
                                    value="<?php echo htmlspecialchars($_POST['expected_salary'] ?? ''); ?>" min="0"
                                    step="1000">
                            </div>
                        <?php endif; ?>

                        <div class="form-group">
                            <label for="availability_date">Available Start Date</label>
                            <input type="date" id="availability_date" name="availability_date"
                                value="<?php echo htmlspecialchars($_POST['availability_date'] ?? ''); ?>"
                                min="<?php echo date('Y-m-d'); ?>">
                        </div>

                        <div class="form-group">
                            <label for="resume">Resume <span class="required">*</span></label>
                            <div class="file-input-wrapper">
                                <input type="file" id="resume" name="resume" class="file-input" accept=".pdf,.doc,.docx"
                                    required>
                                <label for="resume" class="file-input-label">
                                    <span>📎</span> Choose Resume File (PDF, DOC, DOCX)
                                </label>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="cover_letter">Cover Letter <span class="required">*</span></label>
                            <textarea id="cover_letter" name="cover_letter" required
                                placeholder="Tell us why you're interested in this position and why you'd be a great fit..."><?php echo htmlspecialchars($_POST['cover_letter'] ?? ''); ?></textarea>
                        </div>

                        <button type="submit" class="submit-btn">Submit Application</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        // File input enhancement
        document.getElementById('resume').addEventListener('change', function (e) {
            const label = document.querySelector('.file-input-label');
            const fileName = e.target.files[0]?.name;
            if (fileName) {
                label.innerHTML = `<span>📎</span> ${fileName}`;
                label.style.color = '#667eea';
            }
        });
    </script>
</body>

</html>