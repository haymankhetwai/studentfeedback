<?php
session_start();

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
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Feedback Management System</title>

    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <script>
        // Custom Tailwind Color Configuration
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        ucshTeal: {
                            50: '#e8f4f5',
                            600: '#2c6e75',
                            700: '#1f5258',
                            900: '#1a3d44',
                        }
                    }
                }
            }
        }
    </script>
</head>

<body class="bg-gray-100 relative">

    <nav class="sticky top-0 z-40 bg-cyan-600 text-white shadow-lg">
        <div class="container mx-auto px-6 py-4 flex justify-between items-center">

            <div>
                <h1 class="text-3xl font-bold">UCSH</h1>
                <p class="text-sm">University of Computer Studies (Hinthada)</p>
            </div>

            <ul class="hidden md:flex gap-10 items-center font-medium">
                <li><a href="#" class="hover:text-cyan-300 transition">Home</a></li>
                <li><a href="#" class="hover:text-cyan-300 transition">About</a></li>
                <li><a href="departments.php" class="hover:text-cyan-300 transition">Departments</a></li>
                <li>
                    <button onclick="openLoginModal()"
                        class="border border-white px-5 py-2 rounded-lg hover:bg-cyan-500 hover:text-cyan-950 transition focus:outline-none">
                        Login
                    </button>
                </li>
            </ul>

        </div>
    </nav>

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

    <section class="container mx-auto px-6 py-16">
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">

            <div onclick="window.location.href='index.php'"
                class="bg-white p-8 rounded-xl shadow-md hover:shadow-xl transition cursor-pointer">
                <div class="w-16 h-16 bg-cyan-100 rounded-lg flex items-center justify-center text-3xl">🏠</div>
                <h3 class="text-2xl font-bold mt-6">Home</h3>
                <p class="text-gray-600 mt-3">
                    Welcome to the Student Feedback Management System. Get the latest updates and announcements.
                </p>
            </div>

            <div class="bg-white p-8 rounded-xl shadow-md hover:shadow-xl transition">
                <div class="w-16 h-16 bg-cyan-100 rounded-lg flex items-center justify-center text-3xl">ℹ️</div>
                <h3 class="text-2xl font-bold mt-6">About</h3>
                <p class="text-gray-600 mt-3">
                    Learn more about the system, its purpose, framework operations, and system evaluation benefits.
                </p>
            </div>

            <div onclick="window.location.href='departments.php'"
                class="bg-white p-8 rounded-xl shadow-md hover:shadow-xl transition cursor-pointer">
                <div class="w-16 h-16 bg-cyan-100 rounded-lg flex items-center justify-center text-3xl">🏛️</div>
                <h3 class="text-2xl font-bold mt-6">Departments</h3>
                <p class="text-gray-600 mt-3">
                    Explore departments and provide feedback related to courses and faculty.
                </p>
            </div>

            <div onclick="openLoginModal()"
                class="bg-white p-8 rounded-xl shadow-md hover:shadow-xl transition cursor-pointer">
                <div class="w-16 h-16 bg-cyan-100 rounded-lg flex items-center justify-center text-3xl">🔐</div>
                <h3 class="text-2xl font-bold mt-6">Login</h3>
                <p class="text-gray-600 mt-3">
                    Login to your account securely and submit valuable evaluations directly.
                </p>
            </div>

        </div>
    </section>

    <section class="container mx-auto px-6 pb-16">
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

    <footer class="bg-cyan-600 text-white text-center py-5">
        © <?php echo date("Y"); ?> UCSH - Student Feedback Management System. All Rights Reserved.
    </footer>

    <div id="loginModal"
        class="hidden fixed inset-0 z-50 items-center justify-center bg-black/50 backdrop-blur-md transition-opacity duration-300">

        <div
            class="relative w-[90%] max-w-[900px] bg-white/90 backdrop-blur-xl border border-white/60 rounded-[20px] p-8 md:p-10 shadow-2xl text-center mx-4 transform scale-95 transition-transform duration-300">

            <button onclick="closeLoginModal()"
                class="absolute top-6 right-8 text-cyan-600 hover:text-cyan-900 text-2xl transition-colors duration-200 focus:outline-none">
                <i class="fa-solid fa-xmark"></i>
            </button>

            <h2 class="text-cyan-900 text-2xl md:text-3xl font-bold mb-10 tracking-tight">
                Choose Your Login Role
            </h2>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">

                <div
                    class="bg-white border border-black/5 rounded-2xl p-8 shadow-sm flex flex-col items-center justify-between transition-all duration-300 hover:-translate-y-1 hover:shadow-md">
                    <div class="w-[80px] h-[80px] rounded-full bg-cyan-50 flex items-center justify-center mb-6">
                        <i class="fa-solid fa-key text-3xl text-cyan-600"></i>
                    </div>
                    <h3 class="text-xl font-semibold text-gray-900 mb-6">Admin Login</h3>
                    <a href="auth/login.php?role=admin"
                        class="w-full py-3 px-4 bg-cyan-600  hover:bg-cyan-700 text-white font-medium text-sm rounded-lg transition-all duration-200 text-center block">
                        Login as Administrator
                    </a>
                </div>

                <div
                    class="bg-white border border-black/5 rounded-2xl p-8 shadow-sm flex flex-col items-center justify-between transition-all duration-300 hover:-translate-y-1 hover:shadow-md">
                    <div class="w-[80px] h-[80px] rounded-full bg-cyan-50 flex items-center justify-center mb-6">
                        <i class="fa-solid fa-graduation-cap text-3xl text-cyan-600"></i>
                    </div>
                    <h3 class="text-xl font-semibold text-gray-900 mb-6">Teachers Login</h3>
                    <a href="auth/login.php?role=teacher"
                        class="w-full py-3 px-4 bg-cyan-600 hover:bg-cyan-700 text-white font-medium text-sm rounded-lg transition-all duration-200 text-center block">
                        Login as Teacher
                    </a>
                </div>

                <div
                    class="bg-white border border-black/5 rounded-2xl p-8 shadow-sm flex flex-col items-center justify-between transition-all duration-300 hover:-translate-y-1 hover:shadow-md">
                    <div class="w-[80px] h-[80px] rounded-full bg-cyan-50 flex items-center justify-center mb-6">
                        <i class="fa-solid fa-users text-3xl text-cyan-600"></i>
                    </div>
                    <h3 class="text-xl font-semibold text-gray-900 mb-6">Students Login</h3>
                    <a href="auth/login.php?role=student"
                        class="w-full py-3 px-4 bg-cyan-600  hover:bg-cyan-700 text-white font-medium text-sm rounded-lg transition-all duration-200 text-center block">
                        Login as Student
                    </a>
                </div>

            </div>
        </div>
    </div>

    <script>
        const loginModal = document.getElementById('loginModal');

        function openLoginModal() {
            loginModal.classList.remove('hidden');
            loginModal.classList.add('flex');
            setTimeout(() => {
                loginModal.firstElementChild.classList.remove('scale-95');
            }, 10);
        }

        function closeLoginModal() {
            loginModal.firstElementChild.classList.add('scale-95');
            setTimeout(() => {
                loginModal.classList.add('hidden');
                loginModal.classList.remove('flex');
            }, 200);
        }
    </script>

</body>

</html>