<?php
require_once 'config.php';

$error = '';
$success = '';

// Handle success/error messages
if (isset($_GET['success'])) {
    $success = 'Application submitted successfully! We will review your application and contact you soon.';
}
if (isset($_GET['error'])) {
    $error = htmlspecialchars($_GET['error']);
}

// Get job posting ID
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: jobs.php');
    exit();
}

$job_id = (int) $_GET['id'];

// Fetch job posting details
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

// Handle application submission
if ($_POST && isset($_POST['submit_application']) && verifyCSRFToken($_POST['csrf_token'])) {
    if ($deadline_passed) {
        header('Location: job_detail.php?id=' . $job_id . '&error=Application deadline has passed');
        exit();
    }

    $applicant_name = sanitize($_POST['applicant_name']);
    $applicant_email = sanitize($_POST['applicant_email']);
    $applicant_phone = sanitize($_POST['applicant_phone']);
    $cover_letter = sanitize($_POST['cover_letter']);
    $years_experience = !empty($_POST['years_experience']) ? (int) $_POST['years_experience'] : null;
    $expected_salary = !empty($_POST['expected_salary']) ? (float) $_POST['expected_salary'] : null;
    $availability_date = !empty($_POST['availability_date']) ? $_POST['availability_date'] : null;

    // Validation
    $validation_errors = [];

    if (empty($applicant_name) || strlen($applicant_name) < 2) {
        $validation_errors[] = 'Name must be at least 2 characters long';
    }

    if (empty($applicant_email) || !filter_var($applicant_email, FILTER_VALIDATE_EMAIL)) {
        $validation_errors[] = 'Valid email address is required';
    }

    if (empty($cover_letter) || strlen($cover_letter) < 50) {
        $validation_errors[] = 'Cover letter must be at least 50 characters long';
    }

    // Check for duplicate application
    $duplicate_check = $pdo->prepare("SELECT id FROM job_applications WHERE job_posting_id = ? AND applicant_email = ?");
    $duplicate_check->execute([$job_id, $applicant_email]);
    if ($duplicate_check->fetch()) {
        $validation_errors[] = 'You have already applied for this position';
    }

    // Handle file upload (resume)
    $resume_path = null;
    if (isset($_FILES['resume']) && $_FILES['resume']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['resume'];
        $allowed_types = ['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
        $max_size = 5 * 1024 * 1024; // 5MB

        if (!in_array($file['type'], $allowed_types)) {
            $validation_errors[] = 'Resume must be PDF, DOC, or DOCX format';
        } elseif ($file['size'] > $max_size) {
            $validation_errors[] = 'Resume file size must be less than 5MB';
        } else {
            $upload_dir = 'uploads/resumes/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            $filename = uniqid() . '_' . sanitize_file_name($file['name']);
            $resume_path = $upload_dir . $filename;

            if (!move_uploaded_file($file['tmp_name'], $resume_path)) {
                $validation_errors[] = 'Failed to upload resume';
                $resume_path = null;
            }
        }
    } else {
        $validation_errors[] = 'Resume is required';
    }

    if (empty($validation_errors)) {
        // Add after validation but before database insert
        debugLog("Submitting job application", [
            'job_id' => $job_id,
            'applicant_name' => $applicant_name,
            'applicant_email' => $applicant_email,
            'post_data' => $_POST,
            'files' => $_FILES
        ]);

        // Add try-catch around the insert
        try {
            $pdo->beginTransaction();

            // Generate unique application number
            $application_number = 'APP-' . date('Y') . '-' . str_pad(mt_rand(1, 99999), 5, '0', STR_PAD_LEFT);

            // Check if application number exists (very unlikely but safe)
            while (true) {
                $check_stmt = $pdo->prepare("SELECT id FROM job_applications WHERE application_number = ?");
                $check_stmt->execute([$application_number]);
                if (!$check_stmt->fetch())
                    break;
                $application_number = 'APP-' . date('Y') . '-' . str_pad(mt_rand(1, 99999), 5, '0', STR_PAD_LEFT);
            }

            $stmt = $pdo->prepare("
                INSERT INTO job_applications (
                    job_posting_id, applicant_name, applicant_email, applicant_phone,
                    cover_letter, resume_path, years_experience, expected_salary,
                    availability_date, application_number
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $result = $stmt->execute([
                $job_id,
                $applicant_name,
                $applicant_email,
                $applicant_phone,
                $cover_letter,
                $resume_path,
                $years_experience,
                $expected_salary,
                $availability_date,
                $application_number
            ]);
            debugLog("Job application insert result", $result);
            $application_id = $pdo->lastInsertId();
            debugLog("New application ID", $application_id);

            // Notify all admins about new application
            $admin_stmt = $pdo->prepare("SELECT id FROM users WHERE role = 'admin'");
            $admin_stmt->execute();
            $admin_ids = $admin_stmt->fetchAll(PDO::FETCH_COLUMN);

            foreach ($admin_ids as $admin_id) {
                $notification_stmt = $pdo->prepare("
                    INSERT INTO notifications (user_id, type, title, message, related_id)
                    VALUES (?, 'new_application', ?, ?, ?)
                ");
                $notification_stmt->execute([
                    $admin_id,
                    "New Job Application",
                    "New application received for {$job['title']} from {$applicant_name}",
                    $application_id
                ]);
            }

            $pdo->commit();
            header('Location: job_detail.php?id=' . $job_id . '&success=1');
            exit();

        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($resume_path && file_exists($resume_path)) {
                unlink($resume_path);
            }
            debugLog("Job application insert failed", $e->getMessage());
            $error = 'Failed to submit application: ' . $e->getMessage();
        }
    } else {
        if ($resume_path && file_exists($resume_path)) {
            unlink($resume_path);
        }
        $error = implode(' | ', $validation_errors);
    }
}

// Track job view (optional analytics)
try {
    $view_stmt = $pdo->prepare("
        INSERT INTO job_posting_views (job_posting_id, ip_address, user_agent)
        VALUES (?, ?, ?)
    ");
    $view_stmt->execute([
        $job_id,
        $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
    ]);
} catch (Exception $e) {
    // Silently fail - analytics shouldn't break the page
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($job['title']); ?> - Career Opportunity</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #a70202 0%, #000000 100%);
            min-height: 100vh;
            padding: 20px;
        }

        .navbar {
            background: white;
            padding-top: 15px;
            padding-bottom: 15px;
            padding-left: 0;
            padding-right: 0;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            margin-bottom: 20px;
        }

        .navbar-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            max-width: 1200px;
            margin: 0 auto;
        }

        .navbar-brand {
            font-size: 24px;
            font-weight: bold;
            color: #333;
        }

        .navbar-nav {
            display: flex;
            align-items: center;
            gap: 20px;
            margin-left: auto;
        }

        .nav-link {
            color: #333;
            text-decoration: none;
            font-size: 16px;
            transition: color 0.3s;
        }

        .nav-link:hover {
            color: #a70202;
        }

        .container {
            max-width: 900px;
            margin: 0 auto;
        }

        .job-header {
            background: white;
            padding: 30px;
            border-radius: 15px;
            margin-bottom: 20px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
        }

        .job-title {
            font-size: 2.2em;
            color: #333;
            margin-bottom: 15px;
        }

        .job-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            margin-bottom: 20px;
            font-size: 16px;
            color: #666;
        }

        .job-meta span {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .job-type-badge {
            display: inline-block;
            padding: 8px 16px;
            border-radius: 25px;
            font-size: 14px;
            font-weight: bold;
            text-transform: uppercase;
        }

        .type-full_time {
            background: #e3f2fd;
            color: #1976d2;
        }

        .type-part_time {
            background: #f3e5f5;
            color: #7b1fa2;
        }

        .type-contract {
            background: #fff3e0;
            color: #f57c00;
        }

        .type-internship {
            background: #e8f5e8;
            color: #388e3c;
        }

        .job-content {
            background: white;
            padding: 30px;
            border-radius: 15px;
            margin-bottom: 20px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
        }

        .content-section {
            margin-bottom: 25px;
        }

        .content-section h3 {
            color: #333;
            border-bottom: 2px solid #667eea;
            padding-bottom: 10px;
            margin-bottom: 15px;
        }

        .application-form {
            background: white;
            padding: 30px;
            border-radius: 15px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 32px;
            margin-bottom: 15px;
        }

        .form-group {
            margin: 20px;

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
        .form-group textarea,
        .form-group select {
            width: 100%;
            padding: 12px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 14px;
            transition: border-color 0.3s ease;
        }

        .form-group input:focus,
        .form-group textarea:focus,
        .form-group select:focus {
            outline: none;
            border-color: #667eea;
        }

        .form-group textarea {
            min-height: 120px;
            resize: vertical;
        }

        .file-upload {
            position: relative;
            display: inline-block;
            cursor: pointer;
            width: 100%;
        }

        .file-upload-input {
            position: absolute;
            left: -9999px;
        }

        .file-upload-label {
            display: block;
            padding: 12px;
            border: 2px dashed #ccc;
            border-radius: 8px;
            text-align: center;
            transition: all 0.3s ease;
            background: #f8f9fa;
        }

        .file-upload-label:hover {
            border-color: #667eea;
            background: #f0f4ff;
        }

        .submit-btn {
            background: linear-gradient(45deg, #667eea, #764ba2);
            color: white;
            padding: 15px 40px;
            border: none;
            border-radius: 30px;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
            transition: all 0.3s ease;
            width: 100%;
        }

        .submit-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(102, 126, 234, 0.4);
        }

        .submit-btn:disabled {
            background: #ccc;
            cursor: not-allowed;
            transform: none;
        }

        .back-btn {
            background: #6c757d;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            text-decoration: none;
            display: inline-block;
            margin-bottom: 20px;
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

        .deadline-warning {
            background: #fff3cd;
            color: #856404;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            text-align: center;
            font-weight: bold;
        }

        .salary-range {
            background: #e8f5e8;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 15px;
            text-align: center;
        }

        .logo {
            height: 50px;
        }

        @media (max-width: 700px) {
            .form-grid {
                grid-template-columns: 1fr;
                gap: 20px;
            }
        }
    </style>
</head>

<body>
    <nav class="navbar">
        <div class="navbar-container">
            <img src="logo.png" alt="Logo" class="logo">
            <div class="navbar-nav">
                <a href="index.php" class="nav-link">Home</a>
                <a href="jobs.php" class="nav-link">All Jobs</a>
                <a href="login.php" class="nav-link">Employee Login</a>
            </div>
        </div>
    </nav>

    <div class="container">

        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo $error; ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo $success; ?></div>
        <?php endif; ?>

        <!-- Job Details -->
        <div class="job-header">
            <h1 class="job-title"><?php echo htmlspecialchars($job['title']); ?></h1>

            <div class="job-meta">
                <span>🏢 <?php echo htmlspecialchars($job['department']); ?></span>
                <span>📍 <?php echo htmlspecialchars($job['location']); ?></span>
                <span>📅 Posted <?php echo date('F j, Y', strtotime($job['created_at'])); ?></span>
            </div>

            <div class="job-type-badge type-<?php echo $job['employment_type']; ?>">
                <?php echo ucfirst(str_replace('_', ' ', $job['employment_type'])); ?>
            </div>

            <?php if ($job['salary_min'] && $job['salary_max']): ?>
                <div class="salary-range">
                    💰 Salary: <?php echo number_format($job['salary_min']); ?> -
                    <?php echo number_format($job['salary_max']); ?>     <?php echo $job['currency']; ?>
                </div>
            <?php endif; ?>

            <?php if ($job['application_deadline']): ?>
                <?php if ($deadline_passed): ?>
                    <div class="deadline-warning" style="background: #f8d7da; color: #721c24;">
                        ⏰ Application deadline has passed
                        (<?php echo date('F j, Y', strtotime($job['application_deadline'])); ?>)
                    </div>
                <?php else: ?>
                    <div class="deadline-warning">
                        ⏰ Application Deadline: <?php echo date('F j, Y', strtotime($job['application_deadline'])); ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <!-- Job Content -->
        <div class="job-content">
            <div class="content-section">
                <h3>Job Description</h3>
                <p><?php echo nl2br(htmlspecialchars($job['description'])); ?></p>
            </div>

            <div class="content-section">
                <h3>Key Responsibilities</h3>
                <p><?php echo nl2br(htmlspecialchars($job['responsibilities'])); ?></p>
            </div>

            <div class="content-section">
                <h3>Requirements</h3>
                <p><?php echo nl2br(htmlspecialchars($job['requirements'])); ?></p>
            </div>

            <?php if ($job['benefits']): ?>
                <div class="content-section">
                    <h3>Benefits & Perks</h3>
                    <p><?php echo nl2br(htmlspecialchars($job['benefits'])); ?></p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Application Form -->
        <?php if (!$deadline_passed): ?>
            <div class="application-form">
                <h2>Apply for This Position</h2>
                <p>Ready to join our team? Fill out the application form below:</p>

                <form method="POST" action="" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">

                    <div class="form-grid">
                        <div class="form-group">
                            <label for="applicant_name">Full Name <span class="required">*</span></label>
                            <input type="text" id="applicant_name" name="applicant_name" required>
                        </div>
                        <div class="form-group">
                            <label for="applicant_email">Email Address <span class="required">*</span></label>
                            <input type="email" id="applicant_email" name="applicant_email" required>
                        </div>
                    </div>

                    <div class="form-grid">
                        <div class="form-group">
                            <label for="applicant_phone">Phone Number</label>
                            <input type="tel" id="applicant_phone" name="applicant_phone">
                        </div>
                        <div class="form-group">
                            <label for="years_experience">Years of Experience</label>
                            <input type="number" id="years_experience" name="years_experience" min="0" max="50">
                        </div>
                    </div>

                    <div class="form-grid">
                        <div class="form-group">
                            <label for="expected_salary">Expected Salary (<?php echo $job['currency']; ?>)</label>
                            <input type="number" id="expected_salary" name="expected_salary" min="0" step="1000">
                        </div>
                        <div class="form-group">
                            <label for="availability_date">Availability Date</label>
                            <input type="date" id="availability_date" name="availability_date"
                                min="<?php echo date('Y-m-d'); ?>">
                        </div>
                    </div>

                    <div class="form-group full-width">
                        <label for="resume">Resume <span class="required">*</span></label>
                        <div class="file-upload">
                            <input type="file" id="resume" name="resume" accept=".pdf,.doc,.docx" class="file-upload-input"
                                required>
                            <label for="resume" class="file-upload-label">
                                📄 Click to upload your resume (PDF, DOC, DOCX - Max 5MB)
                            </label>
                        </div>
                        <div id="file-name" style="margin-top: 10px; font-size: 14px; color: #666;"></div>
                    </div>

                    <div class="form-group full-width">
                        <label for="cover_letter">Cover Letter <span class="required">*</span></label>
                        <textarea id="cover_letter" name="cover_letter" required
                            placeholder="Tell us why you're interested in this position and what makes you a great fit..."></textarea>
                        <div id="char-count" style="text-align: right; font-size: 12px; color: #666; margin-top: 5px;">0
                            characters (minimum 50)</div>
                    </div>

                    <button type="submit" name="submit_application" class="submit-btn" id="submit-btn">
                        🚀 Submit Application
                    </button>
                </form>
            </div>
        <?php else: ?>
            <div class="application-form">
                <h2>Application Period Closed</h2>
                <p>Unfortunately, the application deadline for this position has passed. Please check our other <a
                        href="jobs.php">available positions</a>.</p>
            </div>
        <?php endif; ?>
    </div>

    <script>
        // File upload feedback
        document.getElementById('resume').addEventListener('change', function () {
            const fileName = this.files[0] ? this.files[0].name : '';
            const fileNameDiv = document.getElementById('file-name');
            const label = document.querySelector('.file-upload-label');

            if (fileName) {
                fileNameDiv.textContent = '✅ Selected: ' + fileName;
                label.style.borderColor = '#28a745';
                label.style.backgroundColor = '#e8f5e8';
            } else {
                fileNameDiv.textContent = '';
                label.style.borderColor = '#ccc';
                label.style.backgroundColor = '#f8f9fa';
            }
        });

        // Cover letter character count
        document.getElementById('cover_letter').addEventListener('input', function () {
            const charCount = this.value.length;
            const countDiv = document.getElementById('char-count');
            const submitBtn = document.getElementById('submit-btn');

            countDiv.textContent = charCount + ' characters (minimum 50)';

            if (charCount < 50) {
                countDiv.style.color = '#dc3545';
                submitBtn.disabled = true;
                submitBtn.textContent = '✍️ Cover letter too short';
            } else {
                countDiv.style.color = '#28a745';
                submitBtn.disabled = false;
                submitBtn.textContent = '🚀 Submit Application';
            }
        });

        // Form validation
        document.querySelector('form').addEventListener('submit', function (e) {
            const coverLetter = document.getElementById('cover_letter').value;
            const resume = document.getElementById('resume').files[0];

            if (coverLetter.length < 50) {
                e.preventDefault();
                alert('Cover letter must be at least 50 characters long.');
                return;
            }

            if (!resume) {
                e.preventDefault();
                alert('Please upload your resume.');
                return;
            }

            // Show loading state
            const submitBtn = document.getElementById('submit-btn');
            submitBtn.disabled = true;
            submitBtn.textContent = '📤 Submitting...';
        });

        // Initialize character count
        document.getElementById('cover_letter').dispatchEvent(new Event('input'));
    </script>
</body>

</html>