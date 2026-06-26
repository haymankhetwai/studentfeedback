-- ============================================================
-- SFMS v6 — Final Database Schema
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
    teacher_code VARCHAR(50) NOT NULL UNIQUE,
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

CREATE TABLE IF NOT EXISTS sections (
    id INT AUTO_INCREMENT PRIMARY KEY,
    course_id INT NOT NULL,
    teacher_id INT NOT NULL,
    academic_year VARCHAR(20) NOT NULL,
    semester VARCHAR(50) DEFAULT NULL,
    section VARCHAR(20) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
    FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE CASCADE
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

CREATE TABLE IF NOT EXISTS feedback_forms (
    id INT AUTO_INCREMENT PRIMARY KEY,
    module ENUM('academic','student_affairs','administration') NOT NULL DEFAULT 'academic',
    section_id INT DEFAULT NULL,
    title VARCHAR(150) NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    status ENUM('active','inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_fb_forms_module (module),
    INDEX idx_fb_forms_status (status)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS feedback_questions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    module ENUM('academic','student_affairs','administration') NOT NULL,
    question_no INT NOT NULL,
    question_text TEXT NOT NULL,
    question_type ENUM('rating','comment') NOT NULL DEFAULT 'rating',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_module_qno (module, question_no),
    INDEX idx_fq_module (module)
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
    form_id INT NOT NULL,
    question_id INT NOT NULL,
    rating ENUM('Good','Fair','Bad') NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (form_id) REFERENCES feedback_forms(id) ON DELETE CASCADE,
    FOREIGN KEY (question_id) REFERENCES feedback_questions(id) ON DELETE CASCADE,
    INDEX idx_fb_ratings_form (form_id),
    INDEX idx_fb_ratings_question (question_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS feedback_comments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    form_id INT NOT NULL,
    question_id INT NOT NULL,
    comment_text TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (form_id) REFERENCES feedback_forms(id) ON DELETE CASCADE,
    FOREIGN KEY (question_id) REFERENCES feedback_questions(id) ON DELETE CASCADE,
    INDEX idx_fb_comments_form (form_id),
    INDEX idx_fb_comments_question (question_id)
) ENGINE=InnoDB;

INSERT IGNORE INTO users (name, username, email, password, role)
VALUES (
    'System Admin',
    'admin',
    'admin@ucsh.edu.mm',
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
    'admin'
);
