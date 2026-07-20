<?php
// ============================================================
// Trend Analysis — Shared Helper Functions
// ============================================================
// These functions are used by admin and teacher trend pages.
// They read-only from the existing database — no modifications.
// ============================================================

/**
 * SQL CASE expression to convert rating values to numeric scores.
 * Reuses the existing system's scoring logic:
 *   Good/Excellent = 5, Fair = 3, Bad/Poor = 1
 */
function trendRatingCase(): string
{
    return "CASE 
        WHEN fr.rating IN ('Excellent','Good','good','3') THEN 5 
        WHEN fr.rating IN ('Fair','fair','Normal','Average','2') THEN 3 
        WHEN fr.rating IN ('Poor','Bad','bad','1') THEN 1 
        ELSE 3 END";
}

// ============================================================
// ACADEMIC MODULE — Per-Teacher, optionally per-Course
// ============================================================

/**
 * Get overall rating trend per Academic Year for a specific teacher.
 * Optionally filtered by course.
 * Returns: [['ay_id','year_name','avg_rating','total_ratings','good_count','fair_count','bad_count'], ...]
 */
function getAcademicRatingTrend(mysqli $conn, int $teacherId, ?int $courseId = null): array
{
    $rc = trendRatingCase();
    $sql = "SELECT ay.id AS ay_id, ay.year_name,
                   ROUND(AVG($rc), 2) AS avg_rating,
                   COUNT(fr.id) AS total_ratings,
                   SUM(CASE WHEN fr.rating IN ('Excellent','Good','good','3') THEN 1 ELSE 0 END) AS good_count,
                   SUM(CASE WHEN fr.rating IN ('Fair','fair','Normal','Average','2') THEN 1 ELSE 0 END) AS fair_count,
                   SUM(CASE WHEN fr.rating IN ('Poor','Bad','bad','1') THEN 1 ELSE 0 END) AS bad_count
            FROM feedback_ratings fr
            JOIN feedback_forms ff ON fr.form_id = ff.id
            JOIN feedback_questions fq ON fr.question_id = fq.id
            JOIN sections sec ON ff.section_id = sec.id
            JOIN academic_years ay ON ff.academic_year_id = ay.id
            WHERE ff.module = 'academic'
              AND fq.question_type = 'rating'
              AND sec.teacher_id = ?";

    $types = 'i';
    $params = [$teacherId];

    if ($courseId) {
        $sql .= " AND sec.course_id = ?";
        $types .= 'i';
        $params[] = $courseId;
    }

    $sql .= " GROUP BY ay.id, ay.year_name ORDER BY ay.year_name ASC";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $result;
}

/**
 * Get per-question rating trends for the academic module.
 * Groups by question_no (ordinal position) to match questions across AYs.
 * Returns: [['year_name','question_no','question_text','avg_rating'], ...]
 */
function getAcademicQuestionTrend(mysqli $conn, int $teacherId, ?int $courseId = null): array
{
    $rc = trendRatingCase();
    $sql = "SELECT ay.year_name, fq.question_no, fq.question_text,
                   ROUND(AVG($rc), 2) AS avg_rating
            FROM feedback_ratings fr
            JOIN feedback_forms ff ON fr.form_id = ff.id
            JOIN feedback_questions fq ON fr.question_id = fq.id
            JOIN sections sec ON ff.section_id = sec.id
            JOIN academic_years ay ON ff.academic_year_id = ay.id
            WHERE ff.module = 'academic'
              AND fq.question_type = 'rating'
              AND sec.teacher_id = ?";

    $types = 'i';
    $params = [$teacherId];

    if ($courseId) {
        $sql .= " AND sec.course_id = ?";
        $types .= 'i';
        $params[] = $courseId;
    }

    $sql .= " GROUP BY ay.year_name, fq.question_no, fq.question_text
              ORDER BY fq.question_no ASC, ay.year_name ASC";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $result;
}

/**
 * Get survey option trends for the academic module.
 * Returns raw counts per (AY, question_no, option_index).
 * Percentages are calculated in PHP.
 * Returns: [['year_name','question_no','question_text','options_json','selected_option_index','cnt'], ...]
 */
function getAcademicSurveyTrend(mysqli $conn, int $teacherId, ?int $courseId = null): array
{
    $sql = "SELECT ay.year_name, fq.question_no, fq.question_text, fq.options_json,
                   fsa.selected_option_index, COUNT(*) AS cnt
            FROM feedback_survey_answers fsa
            JOIN feedback_submissions fsub ON fsa.submission_id = fsub.id
            JOIN feedback_forms ff ON fsub.form_id = ff.id
            JOIN feedback_questions fq ON fsa.question_id = fq.id
            JOIN sections sec ON ff.section_id = sec.id
            JOIN academic_years ay ON ff.academic_year_id = ay.id
            WHERE ff.module = 'academic'
              AND fq.question_type = 'survey'
              AND sec.teacher_id = ?";

    $types = 'i';
    $params = [$teacherId];

    if ($courseId) {
        $sql .= " AND sec.course_id = ?";
        $types .= 'i';
        $params[] = $courseId;
    }

    $sql .= " GROUP BY ay.year_name, fq.question_no, fq.question_text, fq.options_json, fsa.selected_option_index
              ORDER BY fq.question_no ASC, ay.year_name ASC, fsa.selected_option_index ASC";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $result;
}

// ============================================================
// SA / ADMINISTRATION MODULE — No teacher/course, optional semester
// ============================================================

/**
 * Get overall rating trend per Academic Year for SA or Administration module.
 * Optionally filtered by semester.
 */
function getModuleRatingTrend(mysqli $conn, string $module, ?int $semId = null): array
{
    $rc = trendRatingCase();
    $sql = "SELECT ay.id AS ay_id, ay.year_name,
                   ROUND(AVG($rc), 2) AS avg_rating,
                   COUNT(fr.id) AS total_ratings,
                   SUM(CASE WHEN fr.rating IN ('Excellent','Good','good','3') THEN 1 ELSE 0 END) AS good_count,
                   SUM(CASE WHEN fr.rating IN ('Fair','fair','Normal','Average','2') THEN 1 ELSE 0 END) AS fair_count,
                   SUM(CASE WHEN fr.rating IN ('Poor','Bad','bad','1') THEN 1 ELSE 0 END) AS bad_count
            FROM feedback_ratings fr
            JOIN feedback_forms ff ON fr.form_id = ff.id
            JOIN feedback_questions fq ON fr.question_id = fq.id
            JOIN academic_years ay ON ff.academic_year_id = ay.id
            WHERE ff.module = ?
              AND fq.question_type = 'rating'";

    $types = 's';
    $params = [$module];

    if ($semId) {
        $sql .= " AND ff.semester_id = ?";
        $types .= 'i';
        $params[] = $semId;
    }

    $sql .= " GROUP BY ay.id, ay.year_name ORDER BY ay.year_name ASC";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $result;
}

/**
 * Get per-question rating trends for SA or Administration module.
 */
function getModuleQuestionTrend(mysqli $conn, string $module, ?int $semId = null): array
{
    $rc = trendRatingCase();
    $sql = "SELECT ay.year_name, fq.question_no, fq.question_text,
                   ROUND(AVG($rc), 2) AS avg_rating
            FROM feedback_ratings fr
            JOIN feedback_forms ff ON fr.form_id = ff.id
            JOIN feedback_questions fq ON fr.question_id = fq.id
            JOIN academic_years ay ON ff.academic_year_id = ay.id
            WHERE ff.module = ?
              AND fq.question_type = 'rating'";

    $types = 's';
    $params = [$module];

    if ($semId) {
        $sql .= " AND ff.semester_id = ?";
        $types .= 'i';
        $params[] = $semId;
    }

    $sql .= " GROUP BY ay.year_name, fq.question_no, fq.question_text
              ORDER BY fq.question_no ASC, ay.year_name ASC";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $result;
}

/**
 * Get survey option trends for SA or Administration module.
 */
function getModuleSurveyTrend(mysqli $conn, string $module, ?int $semId = null): array
{
    $sql = "SELECT ay.year_name, fq.question_no, fq.question_text, fq.options_json,
                   fsa.selected_option_index, COUNT(*) AS cnt
            FROM feedback_survey_answers fsa
            JOIN feedback_submissions fsub ON fsa.submission_id = fsub.id
            JOIN feedback_forms ff ON fsub.form_id = ff.id
            JOIN feedback_questions fq ON fsa.question_id = fq.id
            JOIN academic_years ay ON ff.academic_year_id = ay.id
            WHERE ff.module = ?
              AND fq.question_type = 'survey'";

    $types = 's';
    $params = [$module];

    if ($semId) {
        $sql .= " AND ff.semester_id = ?";
        $types .= 'i';
        $params[] = $semId;
    }

    $sql .= " GROUP BY ay.year_name, fq.question_no, fq.question_text, fq.options_json, fsa.selected_option_index
              ORDER BY fq.question_no ASC, ay.year_name ASC, fsa.selected_option_index ASC";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $result;
}

// ============================================================
// DROPDOWN DATA — For admin filter dropdowns
// ============================================================

/**
 * Get list of teachers who have academic feedback data.
 */
function getTrendTeachers(mysqli $conn): array
{
    $sql = "SELECT DISTINCT t.id, u.name
            FROM teachers t
            JOIN users u ON t.user_id = u.id
            JOIN sections sec ON sec.teacher_id = t.id
            JOIN feedback_forms ff ON ff.section_id = sec.id
            WHERE ff.module = 'academic'
              AND ff.academic_year_id IS NOT NULL
            ORDER BY u.name ASC";
    return $conn->query($sql)->fetch_all(MYSQLI_ASSOC);
}

/**
 * Get list of courses for a specific teacher that have feedback data.
 * If no teacher specified, returns all courses with academic feedback.
 */
function getTrendCourses(mysqli $conn, ?int $teacherId = null): array
{
    $sql = "SELECT DISTINCT c.id, c.course_code, c.course_name
            FROM courses c
            JOIN sections sec ON sec.course_id = c.id
            JOIN feedback_forms ff ON ff.section_id = sec.id
            WHERE ff.module = 'academic'
              AND ff.academic_year_id IS NOT NULL";

    if ($teacherId) {
        $sql .= " AND sec.teacher_id = " . (int) $teacherId;
    }

    $sql .= " ORDER BY c.course_name ASC";
    return $conn->query($sql)->fetch_all(MYSQLI_ASSOC);
}

/**
 * Get list of semesters that have feedback data for a given module.
 */
function getTrendSemesters(mysqli $conn, string $module): array
{
    $stmt = $conn->prepare(
        "SELECT DISTINCT sm.id, sm.semester_name
         FROM semesters sm
         JOIN feedback_forms ff ON ff.semester_id = sm.id
         WHERE ff.module = ?
           AND ff.academic_year_id IS NOT NULL
         ORDER BY sm.semester_name ASC"
    );
    $stmt->bind_param('s', $module);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $result;
}

// ============================================================
// CALCULATION HELPERS
// ============================================================

/**
 * Calculate improvement percentage between two values.
 * Returns: ((current - previous) / previous) × 100
 */
function calcImprovement(float $current, float $previous): float
{
    if ($previous <= 0) return 0;
    return round((($current - $previous) / $previous) * 100, 2);
}

/**
 * Determine trend status based on improvement percentage.
 * Returns: ['status' => string, 'color' => string, 'icon' => string, 'bg' => string]
 */
function trendStatusInfo(float $improvementPct): array
{
    if ($improvementPct > 2) {
        return [
            'status' => 'Improving',
            'color'  => 'text-emerald-700',
            'bg'     => 'bg-emerald-50 border-emerald-200',
            'icon'   => '📈',
            'badge'  => 'bg-emerald-100 text-emerald-800',
        ];
    } elseif ($improvementPct < -2) {
        return [
            'status' => 'Declining',
            'color'  => 'text-red-700',
            'bg'     => 'bg-red-50 border-red-200',
            'icon'   => '📉',
            'badge'  => 'bg-red-100 text-red-800',
        ];
    } else {
        return [
            'status' => 'Stable',
            'color'  => 'text-amber-700',
            'bg'     => 'bg-amber-50 border-amber-200',
            'icon'   => '➡️',
            'badge'  => 'bg-amber-100 text-amber-800',
        ];
    }
}

/**
 * Build a summary from trend data array.
 * Input: array of ['year_name' => ..., 'avg_rating' => ...]
 * Returns: ['count', 'latest_avg', 'best_year', 'best_avg', 'worst_year', 'worst_avg',
 *           'overall_change_pct', 'trend_info']
 */
function buildTrendSummary(array $trendData): array
{
    $count = count($trendData);
    if ($count === 0) {
        return [
            'count' => 0, 'latest_avg' => 0,
            'best_year' => '—', 'best_avg' => 0,
            'worst_year' => '—', 'worst_avg' => 0,
            'overall_change_pct' => 0,
            'trend_info' => trendStatusInfo(0),
        ];
    }

    $latest = end($trendData);
    $first  = reset($trendData);
    $best   = $trendData[0];
    $worst  = $trendData[0];

    foreach ($trendData as $d) {
        $avg = (float) $d['avg_rating'];
        if ($avg > (float) $best['avg_rating'])  $best = $d;
        if ($avg < (float) $worst['avg_rating']) $worst = $d;
    }

    $overallChange = ($count > 1)
        ? calcImprovement((float) $latest['avg_rating'], (float) $first['avg_rating'])
        : 0;

    return [
        'count'              => $count,
        'latest_avg'         => round((float) $latest['avg_rating'], 2),
        'best_year'          => $best['year_name'],
        'best_avg'           => round((float) $best['avg_rating'], 2),
        'worst_year'         => $worst['year_name'],
        'worst_avg'          => round((float) $worst['avg_rating'], 2),
        'overall_change_pct' => $overallChange,
        'trend_info'         => trendStatusInfo($overallChange),
    ];
}

/**
 * Process raw survey trend data into structured format.
 * Groups by question_no, then by AY, then by option.
 * Calculates percentages automatically.
 *
 * Returns: [question_no => [
 *   'text' => latest question text,
 *   'options' => [option labels from latest AY],
 *   'years' => [year_name, ...],
 *   'data' => [option_index => [year_name => percentage, ...], ...]
 * ]]
 */
function processSurveyTrend(array $rawData): array
{
    if (empty($rawData)) return [];

    // Step 1: Collect raw counts grouped by question_no → year → option_index
    $grouped = [];      // question_no → year → option_index → count
    $qTexts  = [];      // question_no → latest question_text
    $qOpts   = [];      // question_no → latest options_json
    $allYears = [];     // all unique year names

    foreach ($rawData as $row) {
        $qno  = (int) $row['question_no'];
        $year = $row['year_name'];
        $oidx = (int) $row['selected_option_index'];
        $cnt  = (int) $row['cnt'];

        $grouped[$qno][$year][$oidx] = $cnt;
        $qTexts[$qno] = $row['question_text'];   // overwritten with latest
        $qOpts[$qno]  = $row['options_json'];
        $allYears[$year] = true;
    }

    $yearList = array_keys($allYears);
    sort($yearList);

    // Step 2: Build structured output with percentages
    $result = [];
    foreach ($grouped as $qno => $yearData) {
        $options = json_decode($qOpts[$qno] ?? '[]', true) ?: [];
        $optionCount = count($options);

        $data = []; // option_index → [year => pct]
        foreach ($yearData as $year => $optCounts) {
            $total = array_sum($optCounts);
            for ($i = 0; $i < $optionCount; $i++) {
                $c = $optCounts[$i] ?? 0;
                $pct = $total > 0 ? round(($c / $total) * 100, 1) : 0;
                $data[$i][$year] = $pct;
            }
        }

        $result[$qno] = [
            'text'    => $qTexts[$qno],
            'options' => $options,
            'years'   => $yearList,
            'data'    => $data,
        ];
    }

    return $result;
}

/**
 * Process raw question-wise rating data into structured format.
 * Groups by question_no, collects per-AY averages.
 *
 * Returns: [question_no => [
 *   'text' => latest question text,
 *   'years' => [year_name, ...],
 *   'ratings' => [year_name => avg_rating, ...]
 * ]]
 */
function processQuestionTrend(array $rawData): array
{
    if (empty($rawData)) return [];

    $grouped  = [];
    $qTexts   = [];
    $allYears = [];

    foreach ($rawData as $row) {
        $qno  = (int) $row['question_no'];
        $year = $row['year_name'];

        $grouped[$qno][$year] = (float) $row['avg_rating'];
        $qTexts[$qno] = $row['question_text'];
        $allYears[$year] = true;
    }

    $yearList = array_keys($allYears);
    sort($yearList);

    $result = [];
    foreach ($grouped as $qno => $yearData) {
        $result[$qno] = [
            'text'    => $qTexts[$qno],
            'years'   => $yearList,
            'ratings' => $yearData,
        ];
    }

    return $result;
}

/**
 * Color palette for chart datasets (multiple lines/bars).
 */
function trendChartColors(): array
{
    return [
        '#6366f1', '#3b82f6', '#8b5cf6', '#06b6d4', '#10b981',
        '#f59e0b', '#ef4444', '#ec4899', '#14b8a6', '#f97316',
        '#84cc16', '#a855f7', '#0ea5e9', '#d946ef', '#64748b',
    ];
}
