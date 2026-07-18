-- ============================================================
-- SFMS v15 — Final Database Schema
-- Question Sets scoped by (academic_year_id, module) only.
-- One question set per academic year + module, shared across all semesters.
-- ============================================================
CREATE DATABASE IF NOT EXISTS studentfeedbackintern CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE studentfeedbackintern;

-- ============================================================
-- CORE TABLES
-- ============================================================

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    username VARCHAR(50) NOT NULL UNIQUE,
    email VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin','teacher','student') NOT NULL,
    profile_image VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS departments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    department_name VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS teachers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    department_id INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS students (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    roll_no VARCHAR(50) NOT NULL UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS courses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    course_code VARCHAR(50) NOT NULL UNIQUE,
    course_name VARCHAR(100) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS academic_years (
    id INT AUTO_INCREMENT PRIMARY KEY,
    year_name VARCHAR(20) NOT NULL,
    status ENUM('active','inactive') NOT NULL DEFAULT 'inactive',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_year_name (year_name),
    INDEX idx_ay_status (status)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS semesters (
    id INT AUTO_INCREMENT PRIMARY KEY,
    semester_no INT NOT NULL,
    semester_name VARCHAR(50) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_semester_no (semester_no)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS sections (
    id INT AUTO_INCREMENT PRIMARY KEY,
    course_id INT NOT NULL,
    teacher_id INT NOT NULL,
    academic_year VARCHAR(20) NOT NULL,
    semester VARCHAR(50) DEFAULT NULL,
    academic_year_id INT DEFAULT NULL,
    semester_id INT DEFAULT NULL,
    section VARCHAR(20) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
    FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE CASCADE,
    FOREIGN KEY (academic_year_id) REFERENCES academic_years(id) ON DELETE SET NULL,
    FOREIGN KEY (semester_id) REFERENCES semesters(id) ON DELETE SET NULL,
    INDEX idx_sec_academic_year_id (academic_year_id),
    INDEX idx_sec_semester_id (semester_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS section_assignments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    section_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    FOREIGN KEY (section_id) REFERENCES sections(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- FEEDBACK TABLES
-- ============================================================

CREATE TABLE IF NOT EXISTS feedback_question_sets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    academic_year_id INT NOT NULL,
    module ENUM('academic','student_affairs','administration') NOT NULL,
    title VARCHAR(150) NOT NULL,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_question_set (academic_year_id, module),
    FOREIGN KEY (academic_year_id) REFERENCES academic_years(id) ON DELETE RESTRICT,
    INDEX idx_fqs_ay_mod (academic_year_id, module),
    INDEX idx_fqs_module (module),
    INDEX idx_fqs_status (status)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS feedback_forms (
    id INT AUTO_INCREMENT PRIMARY KEY,
    module ENUM('academic','student_affairs','administration') NOT NULL DEFAULT 'academic',
    section_id INT DEFAULT NULL,
    academic_year VARCHAR(20) DEFAULT NULL,
    academic_year_id INT DEFAULT NULL,
    semester_id INT DEFAULT NULL,
    question_set_id INT DEFAULT NULL,
    title VARCHAR(150) NOT NULL,
    university_name VARCHAR(200) DEFAULT NULL,
    university_campus VARCHAR(200) DEFAULT NULL,
    start_date DATETIME NOT NULL,
    end_date DATETIME NOT NULL,
    status ENUM('Upcoming','Active','Expired') NOT NULL DEFAULT 'Upcoming',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (academic_year_id) REFERENCES academic_years(id) ON DELETE SET NULL,
    FOREIGN KEY (semester_id) REFERENCES semesters(id) ON DELETE SET NULL,
    FOREIGN KEY (question_set_id) REFERENCES feedback_question_sets(id) ON DELETE SET NULL,
    INDEX idx_fb_forms_module (module),
    INDEX idx_fb_forms_status (status),
    INDEX idx_fb_forms_ay (academic_year_id),
    INDEX idx_fb_forms_sem (semester_id),
    INDEX idx_fb_forms_qs (question_set_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS feedback_questions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    module ENUM('academic','student_affairs','administration') NOT NULL,
    question_set_id INT DEFAULT NULL,
    question_no INT NOT NULL,
    question_text TEXT NOT NULL,
    options_json TEXT NULL,
    question_type ENUM('rating','comment','survey') NOT NULL DEFAULT 'rating',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (question_set_id) REFERENCES feedback_question_sets(id) ON DELETE SET NULL,
    UNIQUE KEY uq_qs_qno (question_set_id, question_type, question_no),
    INDEX idx_fq_module (module),
    INDEX idx_fq_qs (question_set_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS feedback_submissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    form_id INT NOT NULL,
    student_id INT NOT NULL,
    submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_fb_sub (form_id, student_id),
    FOREIGN KEY (form_id) REFERENCES feedback_forms(id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    INDEX idx_fb_submissions_form (form_id),
    INDEX idx_fb_submissions_student (student_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS feedback_ratings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    form_id INT DEFAULT NULL,
    question_id INT NOT NULL,
    rating ENUM('Good','Fair','Bad') NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (form_id) REFERENCES feedback_forms(id) ON DELETE CASCADE,
    FOREIGN KEY (question_id) REFERENCES feedback_questions(id) ON DELETE CASCADE,
    INDEX idx_fr_form (form_id),
    INDEX idx_fb_ratings_question (question_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS feedback_comments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    form_id INT DEFAULT NULL,
    question_id INT NOT NULL,
    comment_text TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (form_id) REFERENCES feedback_forms(id) ON DELETE CASCADE,
    FOREIGN KEY (question_id) REFERENCES feedback_questions(id) ON DELETE CASCADE,
    INDEX idx_fc_form (form_id),
    INDEX idx_fb_comments_question (question_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS feedback_survey_answers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    submission_id INT NOT NULL,
    question_id INT NOT NULL,
    selected_option_index INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (submission_id) REFERENCES feedback_submissions(id) ON DELETE CASCADE,
    FOREIGN KEY (question_id) REFERENCES feedback_questions(id) ON DELETE CASCADE,
    INDEX idx_fsa_submission (submission_id),
    INDEX idx_fsa_question (question_id),
    INDEX idx_fsa_question_option (question_id, selected_option_index)
) ENGINE=InnoDB;

INSERT IGNORE INTO users (name, username, email, password, role)
VALUES (
    'System Admin',
    'admin',
    'admin@ucsh.edu.mm',
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
    'admin'
);
