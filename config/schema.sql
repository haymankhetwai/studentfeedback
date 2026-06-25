CREATE DATABASE IF NOT EXISTS studentfeedback CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE studentfeedback;

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
);

CREATE TABLE IF NOT EXISTS departments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    department_name VARCHAR(255) NOT NULL,
    description TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS teachers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    department_id INT NOT NULL,
    teacher_code VARCHAR(50) NOT NULL UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_teacher_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_teacher_department FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS students (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    roll_no VARCHAR(50) NOT NULL UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_student_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS courses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    course_code VARCHAR(50) NOT NULL UNIQUE,
    course_name VARCHAR(100) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS sections (
    id INT AUTO_INCREMENT PRIMARY KEY,
    course_id INT NOT NULL,
    teacher_id INT NOT NULL,
    academic_year VARCHAR(20) NOT NULL,
    semester VARCHAR(50) NOT NULL,
    section VARCHAR(20) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_section_course FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
    CONSTRAINT fk_section_teacher FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS section_assignments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    section_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_assignment_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    CONSTRAINT fk_assignment_section FOREIGN KEY (section_id) REFERENCES sections(id) ON DELETE CASCADE,
    UNIQUE(student_id, section_id)
);

CREATE TABLE IF NOT EXISTS feedback_forms (
    id INT AUTO_INCREMENT PRIMARY KEY,
    section_id INT NOT NULL,
    title VARCHAR(100) NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    status ENUM('active','inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_feedback_section FOREIGN KEY(section_id) REFERENCES sections(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS feedback_questions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    feedback_form_id INT NOT NULL,
    question_no INT NOT NULL,
    question_text TEXT NOT NULL,
    question_type ENUM('rating','comment') NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_question_form FOREIGN KEY(feedback_form_id) REFERENCES feedback_forms(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS feedback_submissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    feedback_form_id INT NOT NULL,
    student_id INT NOT NULL,
    submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(feedback_form_id, student_id),
    CONSTRAINT fk_submission_form FOREIGN KEY(feedback_form_id) REFERENCES feedback_forms(id) ON DELETE CASCADE,
    CONSTRAINT fk_submission_student FOREIGN KEY(student_id) REFERENCES students(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS feedback_ratings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    feedback_form_id INT NOT NULL,
    student_id INT NOT NULL,
    question_id INT NOT NULL,
    rating ENUM('Good','Fair','Bad','Excellent','Poor') NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_rating_form FOREIGN KEY(feedback_form_id) REFERENCES feedback_forms(id) ON DELETE CASCADE,
    CONSTRAINT fk_rating_student FOREIGN KEY(student_id) REFERENCES students(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS feedback_comments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    feedback_form_id INT NOT NULL,
    student_id INT NOT NULL,
    question_id INT NOT NULL,
    comment_text TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_comment_form FOREIGN KEY(feedback_form_id) REFERENCES feedback_forms(id) ON DELETE CASCADE,
    CONSTRAINT fk_comment_student FOREIGN KEY(student_id) REFERENCES students(id) ON DELETE CASCADE
);

-- Shared global questions used by all feedback forms/semesters
CREATE TABLE IF NOT EXISTS global_feedback_questions (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    question_no   INT NOT NULL,
    question_text TEXT NOT NULL,
    question_type ENUM('rating','comment') NOT NULL DEFAULT 'rating',
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Shared global questions for Student Affairs feedback (all semesters)
CREATE TABLE IF NOT EXISTS global_sa_feedback_questions (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    question_no   INT NOT NULL,
    question_text TEXT NOT NULL,
    question_type ENUM('rating','comment') NOT NULL DEFAULT 'rating',
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Shared global questions for Administration feedback (all semesters)
CREATE TABLE IF NOT EXISTS global_adm_feedback_questions (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    question_no   INT NOT NULL,
    question_text TEXT NOT NULL,
    question_type ENUM('rating','comment') NOT NULL DEFAULT 'rating',
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

INSERT IGNORE INTO users (name, username, email, password, role)
VALUES (
    'System Admin',
    'admin',
    'admin@ucsh.edu.mm',
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
    'admin'
);

-- ============================================================
-- NEW: STUDENT AFFAIRS FEEDBACK MODULE (5 tables)
-- ============================================================

CREATE TABLE IF NOT EXISTS sa_feedback_forms (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    title       VARCHAR(150) NOT NULL,
    start_date  DATE NOT NULL,
    end_date    DATE NOT NULL,
    status      ENUM('active','inactive') DEFAULT 'active',
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS sa_feedback_questions (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    form_id       INT NOT NULL,
    question_no   INT NOT NULL,
    question_text TEXT NOT NULL,
    question_type ENUM('rating','comment') NOT NULL DEFAULT 'rating',
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_sa_question_form FOREIGN KEY (form_id)
        REFERENCES sa_feedback_forms(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS sa_feedback_submissions (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    form_id      INT NOT NULL,
    student_id   INT NOT NULL,
    submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_sa_sub (form_id, student_id),
    CONSTRAINT fk_sa_sub_form    FOREIGN KEY (form_id)    REFERENCES sa_feedback_forms(id) ON DELETE CASCADE,
    CONSTRAINT fk_sa_sub_student FOREIGN KEY (student_id) REFERENCES students(id)           ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS sa_feedback_ratings (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    form_id     INT NOT NULL,
    question_id INT NOT NULL,
    student_id  INT NOT NULL,
    rating      ENUM('Excellent','Good','Fair','Poor') NOT NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_sa_rat_form     FOREIGN KEY (form_id)     REFERENCES sa_feedback_forms(id)     ON DELETE CASCADE,
    CONSTRAINT fk_sa_rat_question FOREIGN KEY (question_id) REFERENCES sa_feedback_questions(id)  ON DELETE CASCADE,
    CONSTRAINT fk_sa_rat_student  FOREIGN KEY (student_id)  REFERENCES students(id)               ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS sa_feedback_comments (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    form_id      INT NOT NULL,
    question_id  INT NOT NULL,
    student_id   INT NOT NULL,
    comment_text TEXT NOT NULL,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_sa_com_form     FOREIGN KEY (form_id)     REFERENCES sa_feedback_forms(id)     ON DELETE CASCADE,
    CONSTRAINT fk_sa_com_question FOREIGN KEY (question_id) REFERENCES sa_feedback_questions(id)  ON DELETE CASCADE,
    CONSTRAINT fk_sa_com_student  FOREIGN KEY (student_id)  REFERENCES students(id)               ON DELETE CASCADE
);

-- ============================================================
-- NEW: ADMINISTRATION FEEDBACK MODULE (5 tables)
-- ============================================================

CREATE TABLE IF NOT EXISTS adm_feedback_forms (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    title       VARCHAR(150) NOT NULL,
    start_date  DATE NOT NULL,
    end_date    DATE NOT NULL,
    status      ENUM('active','inactive') DEFAULT 'active',
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS adm_feedback_questions (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    form_id       INT NOT NULL,
    question_no   INT NOT NULL,
    question_text TEXT NOT NULL,
    question_type ENUM('rating','comment') NOT NULL DEFAULT 'rating',
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_adm_question_form FOREIGN KEY (form_id)
        REFERENCES adm_feedback_forms(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS adm_feedback_submissions (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    form_id      INT NOT NULL,
    student_id   INT NOT NULL,
    submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_adm_sub (form_id, student_id),
    CONSTRAINT fk_adm_sub_form    FOREIGN KEY (form_id)    REFERENCES adm_feedback_forms(id) ON DELETE CASCADE,
    CONSTRAINT fk_adm_sub_student FOREIGN KEY (student_id) REFERENCES students(id)           ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS adm_feedback_ratings (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    form_id     INT NOT NULL,
    question_id INT NOT NULL,
    student_id  INT NOT NULL,
    rating      ENUM('Excellent','Good','Fair','Poor') NOT NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_adm_rat_form     FOREIGN KEY (form_id)     REFERENCES adm_feedback_forms(id)     ON DELETE CASCADE,
    CONSTRAINT fk_adm_rat_question FOREIGN KEY (question_id) REFERENCES adm_feedback_questions(id)  ON DELETE CASCADE,
    CONSTRAINT fk_adm_rat_student  FOREIGN KEY (student_id)  REFERENCES students(id)                ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS adm_feedback_comments (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    form_id      INT NOT NULL,
    question_id  INT NOT NULL,
    student_id   INT NOT NULL,
    comment_text TEXT NOT NULL,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_adm_com_form     FOREIGN KEY (form_id)     REFERENCES adm_feedback_forms(id)     ON DELETE CASCADE,
    CONSTRAINT fk_adm_com_question FOREIGN KEY (question_id) REFERENCES adm_feedback_questions(id)  ON DELETE CASCADE,
    CONSTRAINT fk_adm_com_student  FOREIGN KEY (student_id)  REFERENCES students(id)                ON DELETE CASCADE
);