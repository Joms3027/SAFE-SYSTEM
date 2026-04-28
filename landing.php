<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="WPU Safe System - Faculty Profile System for West Philippine University">
    <title>WPU Safe System - Faculty & Staff Management</title>
    <!-- Favicon and home screen shortcut icon -->
    <link rel="icon" type="image/png" href="assets/logo.png">
    <link rel="apple-touch-icon" href="assets/logo.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --primary-color: #0a2540;
            --primary-light: #0d3a5c;
            --secondary-color: #0066cc;
            --accent-color: #00a8e8;
            --accent-soft: #e8f4fc;
            --text-dark: #1a2b3c;
            --text-muted: #5a6c7d;
            --text-light: #7f8c8d;
            --bg-light: #f4f7fa;
            --bg-card: #ffffff;
            --white: #ffffff;
            --radius-sm: 8px;
            --radius-md: 12px;
            --radius-lg: 20px;
            --radius-full: 9999px;
            --shadow: 0 2px 12px rgba(10, 37, 64, 0.06);
            --shadow-md: 0 4px 20px rgba(10, 37, 64, 0.08);
            --shadow-lg: 0 8px 40px rgba(10, 37, 64, 0.12);
            --transition: 0.25s cubic-bezier(0.4, 0, 0.2, 1);
        }

        body {
            font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, sans-serif;
            line-height: 1.65;
            color: var(--text-dark);
            overflow-x: hidden;
        }

        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after { animation-duration: 0.01ms !important; transition-duration: 0.01ms !important; }
        }

        .skip-link {
            position: absolute;
            top: -100px;
            left: 1rem;
            padding: 0.6rem 1rem;
            background: var(--primary-color);
            color: var(--white);
            font-weight: 600;
            border-radius: var(--radius-sm);
            z-index: 9999;
            transition: top var(--transition);
        }

        .skip-link:focus {
            top: 1rem;
            outline: 2px solid var(--accent-color);
            outline-offset: 3px;
        }

        /* Navigation */
        .navbar {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            box-shadow: var(--shadow);
            position: fixed;
            top: 0;
            width: 100%;
            z-index: 1000;
            padding: 1rem 2rem;
            transition: background var(--transition), box-shadow var(--transition), padding var(--transition);
        }

        .navbar.scrolled {
            padding: 0.6rem 2rem;
            box-shadow: var(--shadow-md);
        }

        .nav-container {
            max-width: 1200px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            text-decoration: none;
            color: var(--primary-color);
            transition: opacity var(--transition);
        }

        .logo:hover {
            opacity: 0.85;
        }

        .logo .logo-img {
            height: 2rem;
            width: auto;
            display: block;
            object-fit: contain;
        }

        .logo-text {
            font-size: 1.25rem;
            font-weight: 700;
            letter-spacing: -0.02em;
        }

        .nav-links {
            display: flex;
            gap: 2rem;
            list-style: none;
            align-items: center;
        }

        .nav-links a {
            text-decoration: none;
            color: var(--text-dark);
            font-weight: 500;
            font-size: 0.95rem;
            transition: color var(--transition);
        }

        .nav-links a:hover {
            color: var(--secondary-color);
        }

        .nav-links a:focus-visible {
            outline: 2px solid var(--accent-color);
            outline-offset: 4px;
            border-radius: var(--radius-sm);
        }

        .btn {
            padding: 0.8rem 1.6rem;
            border-radius: var(--radius-md);
            text-decoration: none;
            font-weight: 600;
            font-size: 0.95rem;
            transition: all var(--transition);
            display: inline-block;
            border: none;
            cursor: pointer;
        }

        .btn:focus-visible {
            outline: 2px solid var(--accent-color);
            outline-offset: 3px;
        }

        .btn-primary {
            background: var(--primary-color);
            color: var(--white) !important;
            box-shadow: 0 2px 8px rgba(10, 37, 64, 0.25);
        }

        .btn-primary:hover {
            background: var(--primary-light);
            color: var(--white) !important;
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(10, 37, 64, 0.3);
        }

        .btn-outline {
            background: transparent;
            color: var(--primary-color);
            border: 2px solid var(--primary-color);
        }

        .btn-outline:hover {
            background: var(--primary-color);
            color: var(--white) !important;
        }

        .btn-hero-secondary {
            background: rgba(255, 255, 255, 0.15);
            color: var(--white) !important;
            border: 2px solid rgba(255, 255, 255, 0.5);
        }

        .btn-hero-secondary:hover {
            background: rgba(255, 255, 255, 0.25);
            border-color: rgba(255, 255, 255, 0.8);
            color: var(--white) !important;
        }

        .mobile-menu-toggle {
            display: none;
            background: none;
            border: none;
            font-size: 1.5rem;
            color: var(--primary-color);
            cursor: pointer;
            padding: 0.5rem;
        }

        .mobile-menu-toggle:focus-visible {
            outline: 2px solid var(--accent-color);
            outline-offset: 2px;
            border-radius: var(--radius-sm);
        }

        /* Hero Section */
        .hero {
            position: relative;
            background: linear-gradient(145deg, var(--primary-color) 0%, var(--primary-light) 45%, #0a4d7a 100%);
            color: var(--white);
            padding: 160px 2rem 120px;
            text-align: center;
            margin-top: 70px;
            overflow: hidden;
        }

        .hero::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23ffffff' fill-opacity='0.04'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
            opacity: 0.6;
            pointer-events: none;
        }

        .hero-content {
            max-width: 720px;
            margin: 0 auto;
            position: relative;
            z-index: 1;
        }

        .hero h1 {
            font-size: clamp(2rem, 5vw, 3.25rem);
            font-weight: 800;
            margin-bottom: 1rem;
            letter-spacing: -0.03em;
            line-height: 1.2;
            animation: fadeInUp 0.7s ease-out;
        }

        .hero p {
            font-size: clamp(1.05rem, 2vw, 1.25rem);
            margin-bottom: 2rem;
            opacity: 0.95;
            font-weight: 500;
            animation: fadeInUp 0.8s 0.1s ease-out both;
        }

        .hero-buttons {
            display: flex;
            gap: 1rem;
            justify-content: center;
            flex-wrap: wrap;
            animation: fadeInUp 0.9s 0.2s ease-out both;
        }

        .hero-buttons .btn {
            min-width: 160px;
        }

        /* Features Section */
        .features {
            padding: 100px 2rem;
            background: var(--bg-light);
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
        }

        .section-title {
            text-align: center;
            font-size: clamp(1.75rem, 4vw, 2.5rem);
            font-weight: 800;
            margin-bottom: 0.75rem;
            color: var(--primary-color);
            letter-spacing: -0.02em;
        }

        .section-subtitle {
            text-align: center;
            font-size: 1.1rem;
            color: var(--text-muted);
            margin-bottom: 3rem;
            max-width: 560px;
            margin-left: auto;
            margin-right: auto;
            line-height: 1.7;
        }

        .features-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.75rem;
            margin-top: 2rem;
        }

        .feature-card {
            background: var(--bg-card);
            padding: 2rem;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow);
            transition: transform var(--transition), box-shadow var(--transition), border-color var(--transition);
            text-align: center;
            border: 1px solid rgba(10, 37, 64, 0.06);
        }

        .feature-card:hover {
            transform: translateY(-6px);
            box-shadow: var(--shadow-lg);
            border-color: var(--accent-soft);
        }

        .feature-icon {
            width: 64px;
            height: 64px;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            border-radius: var(--radius-md);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1.25rem;
            color: var(--white);
            font-size: 1.5rem;
            transition: transform var(--transition);
        }

        .feature-card:hover .feature-icon {
            transform: scale(1.05);
        }

        .feature-card h3 {
            font-size: 1.2rem;
            font-weight: 700;
            margin-bottom: 0.75rem;
            color: var(--primary-color);
        }

        .feature-card p {
            color: var(--text-muted);
            line-height: 1.7;
            font-size: 0.95rem;
        }

        /* Stats Section */
        .stats {
            background: linear-gradient(145deg, var(--primary-color) 0%, var(--primary-light) 50%, #0a4d7a 100%);
            color: var(--white);
            padding: 72px 2rem;
            position: relative;
        }

        .stats::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url("data:image/svg+xml,%3Csvg width='40' height='40' viewBox='0 0 40 40' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='%23ffffff' fill-opacity='0.03' fill-rule='evenodd'%3E%3Cpath d='M0 40L40 0H20L0 20m40 20V20L20 40'/%3E%3C/g%3E%3C/svg%3E");
            pointer-events: none;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 2rem;
            max-width: 1200px;
            margin: 0 auto;
            position: relative;
            z-index: 1;
        }

        .stat-item {
            text-align: center;
        }

        .stat-item i {
            font-size: 1.75rem;
            opacity: 0.9;
            margin-bottom: 0.75rem;
        }

        .stat-number {
            font-size: clamp(1.75rem, 4vw, 2.5rem);
            font-weight: 800;
            margin-bottom: 0.35rem;
            letter-spacing: -0.02em;
        }

        .stat-label {
            font-size: 0.95rem;
            opacity: 0.9;
            font-weight: 500;
        }

        /* CTA Section */
        .cta {
            background: var(--white);
            padding: 100px 2rem;
            text-align: center;
        }

        .cta-content {
            max-width: 580px;
            margin: 0 auto;
            padding: 3rem 2.5rem;
            background: var(--bg-light);
            border-radius: var(--radius-lg);
            border: 1px solid rgba(10, 37, 64, 0.08);
        }

        .cta h2 {
            font-size: clamp(1.75rem, 3.5vw, 2.25rem);
            font-weight: 800;
            margin-bottom: 0.75rem;
            color: var(--primary-color);
            letter-spacing: -0.02em;
        }

        .cta p {
            font-size: 1.05rem;
            color: var(--text-muted);
            margin-bottom: 2rem;
            line-height: 1.7;
        }

        .cta .hero-buttons {
            justify-content: center;
        }

        /* Footer */
        .footer {
            background: var(--primary-color);
            color: var(--white);
            padding: 48px 2rem;
            text-align: center;
        }

        .footer-content {
            max-width: 1200px;
            margin: 0 auto;
        }

        .footer p {
            opacity: 0.9;
            margin-bottom: 0.4rem;
            font-size: 0.95rem;
        }

        .footer p strong {
            font-weight: 700;
        }

        /* Animations */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(24px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .feature-card {
            animation: fadeInUp 0.6s ease-out both;
        }

        .feature-card:nth-child(1) { animation-delay: 0.05s; }
        .feature-card:nth-child(2) { animation-delay: 0.1s; }
        .feature-card:nth-child(3) { animation-delay: 0.15s; }
        .feature-card:nth-child(4) { animation-delay: 0.2s; }
        .feature-card:nth-child(5) { animation-delay: 0.25s; }
        .feature-card:nth-child(6) { animation-delay: 0.3s; }

        /* Mobile Responsive */
        @media (max-width: 768px) {
            .mobile-menu-toggle {
                display: block;
            }

            .nav-links {
                position: fixed;
                top: 65px;
                left: -100%;
                width: 100%;
                background: var(--white);
                flex-direction: column;
                padding: 2rem;
                box-shadow: var(--shadow-lg);
                transition: left var(--transition);
                gap: 0.5rem;
            }

            .nav-links.active {
                left: 0;
            }

            .nav-links .btn {
                width: 100%;
                text-align: center;
            }

            .hero {
                padding: 130px 1.5rem 90px;
            }

            .hero-buttons {
                flex-direction: column;
                align-items: stretch;
            }

            .hero-buttons .btn {
                width: 100%;
                min-width: unset;
            }

            .features {
                padding: 72px 1.5rem;
            }

            .features-grid {
                grid-template-columns: 1fr;
                gap: 1.25rem;
            }

            .stats {
                padding: 56px 1.5rem;
            }

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 1.5rem;
            }

            .cta-content {
                padding: 2rem 1.5rem;
            }

            .navbar {
                padding: 1rem;
            }

            .navbar.scrolled {
                padding: 0.5rem 1rem;
            }

            .logo-text {
                font-size: 1.1rem;
            }
        }

        @media (max-width: 480px) {
            .hero {
                padding: 110px 1rem 70px;
            }

            .features {
                padding: 56px 1rem;
            }

            .feature-card {
                padding: 1.5rem;
            }

            .stats {
                padding: 44px 1rem;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .stat-item i {
                margin-bottom: 0.5rem;
            }

            .cta {
                padding: 64px 1rem;
            }

            .cta-content {
                padding: 1.75rem 1.25rem;
            }
        }
    </style>
</head>
<body>
    <a href="#main-content" class="skip-link">Skip to main content</a>
    <!-- Navigation -->
    <nav class="navbar">
        <div class="nav-container">
            <a href="#" class="logo">
                <img src="assets/logo.png" alt="WPU Safe System" class="logo-img">
                <span class="logo-text">WPU Safe System</span>
            </a>
            <button class="mobile-menu-toggle" id="mobileMenuToggle">
                <i class="fas fa-bars"></i>
            </button>
            <ul class="nav-links" id="navLinks">
                <li><a href="#features">Features</a></li>
                <li><a href="#about">About</a></li>
                <li><a href="/login" class="btn btn-primary">Login</a></li>
            </ul>
        </div>
    </nav>

    <!-- Hero Section -->
    <main id="main-content">
    <section class="hero">
        <div class="hero-content">
            <h1>WPU Safe System</h1>
            <p>Empowering Education Through Efficient Faculty & Staff Management</p>
            <div class="hero-buttons">
                <a href="/login" class="btn btn-primary">Get Started</a>
                <a href="/station-login" class="btn btn-hero-secondary">Station Login</a>
                <a href="#features" class="btn btn-hero-secondary">Learn More</a>
            </div>
        </div>
    </section>

    <!-- Features Section -->
    <section class="features" id="features">
        <div class="container">
            <h2 class="section-title">Comprehensive Management System</h2>
            <p class="section-subtitle">Everything you need to manage faculty, attendance, and administrative tasks in one place</p>
            
            <div class="features-grid">
                <div class="feature-card">
                    <div class="feature-icon">
                        <i class="fas fa-users"></i>
                    </div>
                    <h3>Faculty Management</h3>
                    <p>Create, edit, and manage faculty accounts with comprehensive profile information and batch upload capabilities.</p>
                </div>

                <div class="feature-card">
                    <div class="feature-icon">
                        <i class="fas fa-qrcode"></i>
                    </div>
                    <h3>QR Code Attendance</h3>
                    <p>Real-time attendance tracking using QR codes. Quick, accurate, and efficient time logging system.</p>
                </div>

                <div class="feature-card">
                    <div class="feature-icon">
                        <i class="fas fa-file-alt"></i>
                    </div>
                    <h3>PDS Management</h3>
                    <p>Complete Civil Service Form 212 (Revised 2017) with auto-save functionality and review workflow.</p>
                </div>

                <div class="feature-card">
                    <div class="feature-icon">
                        <i class="fas fa-dollar-sign"></i>
                    </div>
                    <h3>Payroll System</h3>
                    <p>Automated salary calculation with deductions management (SSS, PhilHealth, Pag-IBIG, Tax).</p>
                </div>

                <div class="feature-card">
                    <div class="feature-icon">
                        <i class="fas fa-tasks"></i>
                    </div>
                    <h3>Requirements Tracking</h3>
                    <p>Create custom requirements, set deadlines, and track submission status for all faculty members.</p>
                </div>

                <div class="feature-card">
                    <div class="feature-icon">
                        <i class="fas fa-chart-bar"></i>
                    </div>
                    <h3>Analytics Dashboard</h3>
                    <p>Comprehensive system statistics with visual charts and graphs for better decision-making.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- Stats Section -->
    <section class="stats">
        <div class="stats-grid">
            <div class="stat-item">
                <i class="fas fa-mobile-alt" aria-hidden="true"></i>
                <div class="stat-number">100%</div>
                <div class="stat-label">Portable System</div>
            </div>
            <div class="stat-item">
                <i class="fas fa-clock" aria-hidden="true"></i>
                <div class="stat-number">24/7</div>
                <div class="stat-label">Access Available</div>
            </div>
            <div class="stat-item">
                <i class="fas fa-sync-alt" aria-hidden="true"></i>
                <div class="stat-number">Real-time</div>
                <div class="stat-label">Attendance Tracking</div>
            </div>
            <div class="stat-item">
                <i class="fas fa-shield-alt" aria-hidden="true"></i>
                <div class="stat-number">Secure</div>
                <div class="stat-label">Data Management</div>
            </div>
        </div>
    </section>

    <!-- CTA Section -->
    <section class="cta" id="about">
        <div class="cta-content">
            <h2>Ready to Get Started?</h2>
            <p>Join West Philippine University's comprehensive faculty and staff management system. Streamline your workflow and enhance productivity.</p>
            <div class="hero-buttons">
                <a href="/login" class="btn btn-primary">Login to Your Account</a>
                <a href="/station-login" class="btn btn-outline">Station Login</a>
            </div>
        </div>
    </section>

    </main>
    <!-- Footer -->
    <footer class="footer">
        <div class="footer-content">
            <p><strong>WPU Safe System - Faculty Profile System</strong></p>
            <p>West Philippine University</p>
            <p>&copy; 2026 All Rights Reserved</p>
        </div>
    </footer>

    <script>
        // Mobile menu toggle
        const mobileMenuToggle = document.getElementById('mobileMenuToggle');
        const navLinks = document.getElementById('navLinks');

        mobileMenuToggle.addEventListener('click', () => {
            navLinks.classList.toggle('active');
            const icon = mobileMenuToggle.querySelector('i');
            if (navLinks.classList.contains('active')) {
                icon.classList.remove('fa-bars');
                icon.classList.add('fa-times');
            } else {
                icon.classList.remove('fa-times');
                icon.classList.add('fa-bars');
            }
        });

        // Close mobile menu when clicking on a link
        document.querySelectorAll('.nav-links a').forEach(link => {
            link.addEventListener('click', () => {
                navLinks.classList.remove('active');
                const icon = mobileMenuToggle.querySelector('i');
                icon.classList.remove('fa-times');
                icon.classList.add('fa-bars');
            });
        });

        // Smooth scrolling for anchor links
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    target.scrollIntoView({
                        behavior: 'smooth',
                        block: 'start'
                    });
                }
            });
        });

        // Navbar scroll state (shrink + shadow)
        window.addEventListener('scroll', () => {
            const navbar = document.querySelector('.navbar');
            if (window.scrollY > 40) {
                navbar.classList.add('scrolled');
            } else {
                navbar.classList.remove('scrolled');
            }
        });
    </script>
</body>
</html>
