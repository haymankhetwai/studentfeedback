<?php
session_start();
require_once 'includes/functions.php';

// If user is already logged in, redirect to their respective dashboard
if (isset($_SESSION['user_id']) && isset($_SESSION['role'])) {
    switch ($_SESSION['role']) {
        case 'admin':
            header("Location: admin/index.php");
            exit;
        case 'teacher':
            header("Location: teacher/index.php");
            exit;
        case 'student':
            header("Location: student/index.php");
            exit;
    }
}

$pageTitle = 'Student Feedback Management System';
$showNav = true;
include 'includes/header.php';
?>

    <section class="relative h-[650px] bg-slate-200 overflow-hidden">
        <img src="assets/uploads/ucsh.jpg" alt="UCSH Campus" class="absolute inset-0 w-full h-full object-cover">
        <div class="absolute inset-0 bg-gradient-to-r from-white via-black/10 to-transparent"></div>

        <div class="relative h-full flex items-center">
            <div class="container mx-auto px-6">
                <div class="max-w-xl">
                    <h1 class="text-5xl md:text-6xl font-bold text-cyan-950 leading-tight">
                        Welcome to the Student Feedback Management System
                    </h1>
                    <p class="mt-6 text-lg text-gray-900 font-medium p-3 ">
                        Students can submit feedback only for the semester assigned by the administrator.
                        Teachers and administrators can review reports and manage feedback efficiently.
                    </p>
                    <button onclick="openLoginModal()"
                        class="mt-8 bg-cyan-600 text-white px-8 py-3 rounded-lg hover:bg-cyan-700 transition font-semibold shadow-md">
                        Get Started
                    </button>
                </div>
            </div>
        </div>
    </section>

    <section class="container mx-auto px-6 pb-16 mt-10">
        <div class="bg-gradient-to-r from-cyan-50 to-gray-50 p-10 rounded-2xl shadow">
            <div class="flex flex-col md:flex-row justify-between items-center gap-8">
                <div class="flex items-center gap-5">
                    <div class="text-5xl">👥</div>
                    <div>
                        <h2 class="text-3xl font-bold text-cyan-950">We Value Your Opinion</h2>
                        <p class="text-gray-600 mt-2">
                            Your feedback is essential in helping us maintain quality education and excellent services
                            at UCSH.
                        </p>
                    </div>
                </div>
                <div class="text-center">
                    <h2 class="text-4xl font-bold text-cyan-950">UCSH</h2>
                    <p class="text-gray-600">University of Computer Studies Hinthada</p>
                </div>
            </div>
        </div>
    </section>

<?php include 'includes/footer.php'; ?>
