<?php
session_start();


if (isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true) {
   
    if (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin') {
        header("Location: dashboard.php");
        exit;
    } else {
      
        header("Location: student_dashboard.php");
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Welcome Page</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
       
        :root {
       
            --primary-accent: #cc0000; 
        
            --primary-accent-hover: #990000;
            
 
            --dark-overlay: rgba(0, 0, 0, 0.7);
            

            --text-light: #ffffff;
            --text-creamy: #f4f4f4;
            
            --font-main: 'Montserrat', sans-serif;
        }

   
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body, html { height: 100%; font-family: var(--font-main); }

     
        .hero-section {
         
            background-image: url('../images/landingbg.jpg'); 
            
            background-size: cover;
            background-position: center center;
            background-repeat: no-repeat;
            height: 100vh;
            width: 100%;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            text-align: center;
            position: relative;
        }

        .hero-overlay {
            position: absolute;
            top: 0; left: 0; width: 100%; height: 100%;
          
            background: linear-gradient(to bottom, rgba(50, 0, 0, 0.4), var(--dark-overlay));
            z-index: 1;
        }

        .hero-content {
            z-index: 2;
            padding: 20px;
            max-width: 900px;
            animation: fadeUp 1.2s ease-out;
        }

      
        .hero-title {
            color: var(--text-light);
            font-size: 3.5rem;
            font-weight: 700;
            margin-bottom: 1.5rem;
            text-shadow: 2px 2px 10px rgba(0,0,0,0.5);
            line-height: 1.2;
        }

        .hero-subtitle {
            color: var(--text-creamy);
            font-size: 1.25rem;
            margin-bottom: 3rem;
            font-weight: 400;
            max-width: 700px;
            margin-left: auto; margin-right: auto;
            line-height: 1.6;
        }

        .highlight { color: var(--primary-accent); text-shadow: 0px 0px 10px rgba(0,0,0,0.8); }

       
        .cta-container { display: flex; gap: 20px; justify-content: center; flex-wrap: wrap; }

        .btn {
            padding: 15px 40px;
            border-radius: 50px;
            font-size: 1rem;
            font-weight: 600;
            text-decoration: none;
            text-transform: uppercase;
            letter-spacing: 1px;
            transition: all 0.3s ease;
            border: 2px solid transparent;
            cursor: pointer;
        }

  
        .btn-primary {
            background-color: var(--primary-accent);
            color: var(--text-light);
            box-shadow: 0 4px 15px rgba(204, 0, 0, 0.4);
        }
        .btn-primary:hover {
            background-color: var(--primary-accent-hover);
            transform: translateY(-3px);
            box-shadow: 0 6px 20px rgba(204, 0, 0, 0.6);
        }

   
        .btn-secondary {
            background-color: transparent;
            color: var(--text-light);
            border-color: var(--text-light);
        }
        .btn-secondary:hover {
            background-color: var(--text-light);
            color: #cc0000; 
            transform: translateY(-3px);
        }

    
        .navbar {
            position: absolute;
            top: 0; left: 0; width: 100%;
            padding: 25px 50px;
            z-index: 10;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .nav-logo-container {
            display: flex;
            align-items: center;
            text-decoration: none;
            gap: 15px;
        }

        .school-logo {
            height: 50px;
            width: auto;
            border-radius: 50%;
            border: 2px solid rgba(255,255,255,0.8);
            box-shadow: 0 0 10px rgba(0,0,0,0.5);
        }

        .nav-logo-text {
            color: var(--text-light);
            font-weight: 700;
            font-size: 1.5rem;
            text-shadow: 1px 1px 4px rgba(0,0,0,0.5);
        }

        .nav-links-container { display: flex; align-items: center; }
        .nav-link {
            color: var(--text-creamy);
            text-decoration: none;
            margin-left: 30px;
            font-weight: 600;
            font-size: 0.9rem;
            transition: color 0.3s ease;
        }
        .nav-link:hover { color: var(--primary-accent); }

        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @media (max-width: 768px) {
            .hero-title { font-size: 2.5rem; }
            .hero-subtitle { font-size: 1.1rem; }
            .navbar { padding: 20px; flex-direction: column; gap: 15px; }
            .nav-links-container { display: none; }
            .btn { width: 100%; max-width: 300px; }
        }
    </style>
</head>
<body>

    <nav class="navbar">
        <a href="#" class="nav-logo-container">
            <img src="../images/logowmsu.jpg" alt="School Logo" class="school-logo">
            <span class="nav-logo-text">Digital Class Recitation</span>
        </a>

        <div class="nav-links-container">
            <a href="login.php" class="nav-link highlight">Login</a>
            <a href="register_student.php" class="nav-link highlight">Register</a>
        </div>
    </nav>

    <main class="hero-section">
        <div class="hero-overlay"></div>
        <div class="hero-content">
            <h1 class="hero-title">
                Welcome to the Future of <span class="highlight">Digital Recitation</span>
            </h1>
            
            <p class="hero-subtitle">
                Streamline classroom engagement, track performance real-time, and enhance the learning experience with our advanced academic management platform.
            </p>

            <div class="cta-container">
                <a href="register_student.php" class="btn btn-primary">Register</a>
                <a href="login.php" class="btn btn-secondary">Login</a>
            </div>
        </div>
    </main>

</body>
</html>