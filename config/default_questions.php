<?php
/**
 * Default Question Templates for each module.
 * These are auto-inserted when a Question Set is created.
 * Each entry: ['no' => N, 'text' => '...', 'type' => 'rating'|'comment'|'survey', 'options' => null|array]
 */

function getDefaultQuestions(string $module): array
{
    $questions = match ($module) {
        'academic' => getDefaultAcademicQuestions(),
        'student_affairs' => getDefaultSAQuestions(),
        'administration' => getDefaultAdminQuestions(),
        default => [],
    };
    return $questions;
}

function getDefaultAcademicQuestions(): array
{
    return [
        ['no' => 1,  'text' => 'The teacher is well-prepared for each class session.', 'type' => 'rating', 'options' => null],
        ['no' => 2,  'text' => 'The teacher explains concepts clearly and effectively.', 'type' => 'rating', 'options' => null],
        ['no' => 3,  'text' => 'The teacher encourages student participation and questions.', 'type' => 'rating', 'options' => null],
        ['no' => 4,  'text' => 'The teacher provides timely and constructive feedback.', 'type' => 'rating', 'options' => null],
        ['no' => 5,  'text' => 'The course content is relevant and up-to-date.', 'type' => 'rating', 'options' => null],
        ['no' => 6,  'text' => 'The course syllabus is clear and well-structured.', 'type' => 'rating', 'options' => null],
        ['no' => 7,  'text' => 'The teaching materials (slides, handouts, etc.) are helpful.', 'type' => 'rating', 'options' => null],
        ['no' => 8,  'text' => 'The teacher uses effective teaching methods and techniques.', 'type' => 'rating', 'options' => null],
        ['no' => 9,  'text' => 'The teacher is approachable and available outside class.', 'type' => 'rating', 'options' => null],
        ['no' => 10, 'text' => 'The teacher respects all students equally.', 'type' => 'rating', 'options' => null],
        ['no' => 11, 'text' => 'The teacher manages class time effectively.', 'type' => 'rating', 'options' => null],
        ['no' => 12, 'text' => 'The teacher provides adequate examples and demonstrations.', 'type' => 'rating', 'options' => null],
        ['no' => 13, 'text' => 'The teacher uses technology effectively in teaching.', 'type' => 'rating', 'options' => null],
        ['no' => 14, 'text' => 'The teacher connects theoretical knowledge to practical applications.', 'type' => 'rating', 'options' => null],
        ['no' => 15, 'text' => 'The teacher motivates students to learn.', 'type' => 'rating', 'options' => null],
        ['no' => 16, 'text' => 'The teacher is fair in assessments and grading.', 'type' => 'rating', 'options' => null],
        ['no' => 17, 'text' => 'The teacher handles questions from students patiently.', 'type' => 'rating', 'options' => null],
        ['no' => 18, 'text' => 'The teacher maintains a positive learning environment.', 'type' => 'rating', 'options' => null],
        ['no' => 19, 'text' => 'The teacher provides supplementary learning resources.', 'type' => 'rating', 'options' => null],
        ['no' => 20, 'text' => 'The teacher follows the planned course schedule.', 'type' => 'rating', 'options' => null],
        ['no' => 21, 'text' => 'The teacher is punctual and starts class on time.', 'type' => 'rating', 'options' => null],
        ['no' => 22, 'text' => 'The teacher clearly communicates learning objectives.', 'type' => 'rating', 'options' => null],
        ['no' => 23, 'text' => 'The teacher adapts teaching pace to student understanding.', 'type' => 'rating', 'options' => null],
        ['no' => 24, 'text' => 'The teacher promotes critical thinking and analysis.', 'type' => 'rating', 'options' => null],
        ['no' => 25, 'text' => 'The teacher provides real-world case studies and examples.', 'type' => 'rating', 'options' => null],
        ['no' => 26, 'text' => 'The teacher encourages collaborative learning.', 'type' => 'rating', 'options' => null],
        ['no' => 27, 'text' => 'The teacher effectively uses assessment results to improve teaching.', 'type' => 'rating', 'options' => null],
        ['no' => 28, 'text' => 'The teacher provides clear instructions for assignments and projects.', 'type' => 'rating', 'options' => null],
        ['no' => 29, 'text' => 'The teacher is knowledgeable in the subject matter.', 'type' => 'rating', 'options' => null],
        ['no' => 30, 'text' => 'The teacher creates an inclusive classroom atmosphere.', 'type' => 'rating', 'options' => null],
        ['no' => 31, 'text' => 'The course workload is reasonable and manageable.', 'type' => 'rating', 'options' => null],
        ['no' => 32, 'text' => 'The examination questions are fair and test understanding.', 'type' => 'rating', 'options' => null],
        ['no' => 33, 'text' => 'The teacher provides opportunities for student feedback.', 'type' => 'rating', 'options' => null],
        ['no' => 34, 'text' => 'The teacher uses group activities to enhance learning.', 'type' => 'rating', 'options' => null],
        ['no' => 35, 'text' => 'The teacher is enthusiastic about the subject.', 'type' => 'rating', 'options' => null],
        ['no' => 36, 'text' => 'The teacher provides clear rubrics for evaluation.', 'type' => 'rating', 'options' => null],
        ['no' => 37, 'text' => 'The teacher addresses student concerns promptly.', 'type' => 'rating', 'options' => null],
        ['no' => 38, 'text' => 'The teacher promotes academic integrity and honesty.', 'type' => 'rating', 'options' => null],
        ['no' => 39, 'text' => 'The teacher uses varied assessment methods.', 'type' => 'rating', 'options' => null],
        ['no' => 40, 'text' => 'The teacher is responsive to emails and messages.', 'type' => 'rating', 'options' => null],
        ['no' => 41, 'text' => 'The teacher provides extra help when needed.', 'type' => 'rating', 'options' => null],
        ['no' => 42, 'text' => 'The teacher makes complex topics easier to understand.', 'type' => 'rating', 'options' => null],
        ['no' => 43, 'text' => 'The teacher encourages independent learning.', 'type' => 'rating', 'options' => null],
        ['no' => 44, 'text' => 'The teacher provides regular progress updates.', 'type' => 'rating', 'options' => null],
        ['no' => 45, 'text' => 'The teacher uses professional and respectful language.', 'type' => 'rating', 'options' => null],
        ['no' => 46, 'text' => 'The teacher is committed to student success.', 'type' => 'rating', 'options' => null],
        ['no' => 47, 'text' => 'The teacher provides relevant industry insights.', 'type' => 'rating', 'options' => null],
        ['no' => 48, 'text' => 'The teacher supports student career development.', 'type' => 'rating', 'options' => null],
        ['no' => 49, 'text' => 'The teacher promotes research and innovation.', 'type' => 'rating', 'options' => null],
        ['no' => 50, 'text' => 'The teacher maintains professionalism at all times.', 'type' => 'rating', 'options' => null],
        ['no' => 51, 'text' => 'The teacher facilitates effective class discussions.', 'type' => 'rating', 'options' => null],
        ['no' => 52, 'text' => 'The teacher provides constructive criticism on student work.', 'type' => 'rating', 'options' => null],
        ['no' => 53, 'text' => 'The teacher adapts to different learning styles.', 'type' => 'rating', 'options' => null],
        ['no' => 54, 'text' => 'The teacher uses visual aids and multimedia effectively.', 'type' => 'rating', 'options' => null],
        ['no' => 55, 'text' => 'The teacher is available during office hours.', 'type' => 'rating', 'options' => null],
        // Comment questions
        ['no' => 1, 'text' => 'What do you like most about this course and teacher?', 'type' => 'comment', 'options' => null],
        ['no' => 2, 'text' => 'What improvements would you suggest for this course?', 'type' => 'comment', 'options' => null],
        ['no' => 3, 'text' => 'Any additional comments or suggestions for the teacher?', 'type' => 'comment', 'options' => null],
    ];
}

function getDefaultSAQuestions(): array
{
    return [
        ['no' => 1,  'text' => 'The Student Affairs office provides timely assistance.', 'type' => 'rating', 'options' => null],
        ['no' => 2,  'text' => 'The Student Affairs staff is friendly and helpful.', 'type' => 'rating', 'options' => null],
        ['no' => 3,  'text' => 'The Student Affairs office is well-organized.', 'type' => 'rating', 'options' => null],
        ['no' => 4,  'text' => 'The Student Affairs office responds to inquiries promptly.', 'type' => 'rating', 'options' => null],
        ['no' => 5,  'text' => 'Student Affairs provides adequate support for student needs.', 'type' => 'rating', 'options' => null],
        ['no' => 6,  'text' => 'The Student Affairs office communicates effectively with students.', 'type' => 'rating', 'options' => null],
        ['no' => 7,  'text' => 'Student Affairs organizes useful activities and events.', 'type' => 'rating', 'options' => null],
        ['no' => 8,  'text' => 'The Student Affairs office handles complaints fairly.', 'type' => 'rating', 'options' => null],
        ['no' => 9,  'text' => 'Student Affairs provides adequate counseling services.', 'type' => 'rating', 'options' => null],
        ['no' => 10, 'text' => 'The Student Affairs office maintains a welcoming environment.', 'type' => 'rating', 'options' => null],
        ['no' => 11, 'text' => 'Student Affairs provides sufficient scholarship information.', 'type' => 'rating', 'options' => null],
        ['no' => 12, 'text' => 'The Student Affairs office supports student well-being.', 'type' => 'rating', 'options' => null],
        ['no' => 13, 'text' => 'Student Affairs handles disciplinary matters fairly.', 'type' => 'rating', 'options' => null],
        ['no' => 14, 'text' => 'The Student Affairs office is accessible and available.', 'type' => 'rating', 'options' => null],
        ['no' => 15, 'text' => 'Student Affairs provides adequate health and safety support.', 'type' => 'rating', 'options' => null],
        ['no' => 16, 'text' => 'The Student Affairs office promotes extracurricular activities.', 'type' => 'rating', 'options' => null],
        ['no' => 17, 'text' => 'Student Affairs provides adequate accommodation support.', 'type' => 'rating', 'options' => null],
        ['no' => 18, 'text' => 'The Student Affairs office handles financial aid fairly.', 'type' => 'rating', 'options' => null],
        ['no' => 19, 'text' => 'Student Affairs supports student organizations effectively.', 'type' => 'rating', 'options' => null],
        ['no' => 20, 'text' => 'The Student Affairs office provides adequate career guidance.', 'type' => 'rating', 'options' => null],
        ['no' => 21, 'text' => 'Student Affairs organizes workshops and training sessions.', 'type' => 'rating', 'options' => null],
        ['no' => 22, 'text' => 'The Student Affairs office handles student records properly.', 'type' => 'rating', 'options' => null],
        ['no' => 23, 'text' => 'Student Affairs provides adequate emergency support.', 'type' => 'rating', 'options' => null],
        ['no' => 24, 'text' => 'The Student Affairs office is professional and courteous.', 'type' => 'rating', 'options' => null],
        ['no' => 25, 'text' => 'Student Affairs promotes a positive campus culture.', 'type' => 'rating', 'options' => null],
        ['no' => 26, 'text' => 'The Student Affairs office handles student grievances promptly.', 'type' => 'rating', 'options' => null],
        ['no' => 27, 'text' => 'Student Affairs provides adequate international student support.', 'type' => 'rating', 'options' => null],
        ['no' => 28, 'text' => 'The Student Affairs office maintains good communication channels.', 'type' => 'rating', 'options' => null],
        ['no' => 29, 'text' => 'Student Affairs supports student mental health programs.', 'type' => 'rating', 'options' => null],
        ['no' => 30, 'text' => 'The Student Affairs office is responsive to student feedback.', 'type' => 'rating', 'options' => null],
        // Comment questions
        ['no' => 1, 'text' => 'What do you appreciate most about Student Affairs?', 'type' => 'comment', 'options' => null],
        ['no' => 2, 'text' => 'What improvements would you suggest for Student Affairs?', 'type' => 'comment', 'options' => null],
        ['no' => 3, 'text' => 'Any additional comments or suggestions?', 'type' => 'comment', 'options' => null],
    ];
}

function getDefaultAdminQuestions(): array
{
    return [
        ['no' => 1,  'text' => 'The administration office provides timely assistance.', 'type' => 'rating', 'options' => null],
        ['no' => 2,  'text' => 'The administrative staff is professional and courteous.', 'type' => 'rating', 'options' => null],
        ['no' => 3,  'text' => 'The administration office is well-organized.', 'type' => 'rating', 'options' => null],
        ['no' => 4,  'text' => 'The administration office responds to inquiries promptly.', 'type' => 'rating', 'options' => null],
        ['no' => 5,  'text' => 'The administration processes documents efficiently.', 'type' => 'rating', 'options' => null],
        ['no' => 6,  'text' => 'The administration office maintains accurate records.', 'type' => 'rating', 'options' => null],
        ['no' => 7,  'text' => 'The administration provides clear guidelines and procedures.', 'type' => 'rating', 'options' => null],
        ['no' => 8,  'text' => 'The administration handles student requests fairly.', 'type' => 'rating', 'options' => null],
        ['no' => 9,  'text' => 'The administration office is accessible during working hours.', 'type' => 'rating', 'options' => null],
        ['no' => 10, 'text' => 'The administration communicates effectively with students.', 'type' => 'rating', 'options' => null],
        ['no' => 11, 'text' => 'The administration handles fee-related matters efficiently.', 'type' => 'rating', 'options' => null],
        ['no' => 12, 'text' => 'The administration office processes certificates promptly.', 'type' => 'rating', 'options' => null],
        ['no' => 13, 'text' => 'The administration provides adequate support for registration.', 'type' => 'rating', 'options' => null],
        ['no' => 14, 'text' => 'The administration office handles examination logistics well.', 'type' => 'rating', 'options' => null],
        ['no' => 15, 'text' => 'The administration maintains a clean and organized office.', 'type' => 'rating', 'options' => null],
        ['no' => 16, 'text' => 'The administration handles complaints and feedback fairly.', 'type' => 'rating', 'options' => null],
        ['no' => 17, 'text' => 'The administration provides adequate library services.', 'type' => 'rating', 'options' => null],
        ['no' => 18, 'text' => 'The administration handles IT support requests promptly.', 'type' => 'rating', 'options' => null],
        ['no' => 19, 'text' => 'The administration office provides clear communication.', 'type' => 'rating', 'options' => null],
        ['no' => 20, 'text' => 'The administration handles student ID and documentation properly.', 'type' => 'rating', 'options' => null],
        ['no' => 21, 'text' => 'The administration processes transcript requests efficiently.', 'type' => 'rating', 'options' => null],
        ['no' => 22, 'text' => 'The administration provides adequate facility management.', 'type' => 'rating', 'options' => null],
        ['no' => 23, 'text' => 'The administration handles scheduling and timetabling well.', 'type' => 'rating', 'options' => null],
        ['no' => 24, 'text' => 'The administration office is responsive to student needs.', 'type' => 'rating', 'options' => null],
        ['no' => 25, 'text' => 'The administration maintains proper security measures.', 'type' => 'rating', 'options' => null],
        ['no' => 26, 'text' => 'The administration provides adequate parking facilities.', 'type' => 'rating', 'options' => null],
        ['no' => 27, 'text' => 'The administration handles transport services well.', 'type' => 'rating', 'options' => null],
        ['no' => 28, 'text' => 'The administration office maintains good customer service.', 'type' => 'rating', 'options' => null],
        ['no' => 29, 'text' => 'The administration handles document verification promptly.', 'type' => 'rating', 'options' => null],
        ['no' => 30, 'text' => 'The administration provides adequate support for events.', 'type' => 'rating', 'options' => null],
        // Comment questions
        ['no' => 1, 'text' => 'What do you appreciate most about the administration?', 'type' => 'comment', 'options' => null],
        ['no' => 2, 'text' => 'What improvements would you suggest for the administration?', 'type' => 'comment', 'options' => null],
        ['no' => 3, 'text' => 'Any additional comments or suggestions?', 'type' => 'comment', 'options' => null],
    ];
}
