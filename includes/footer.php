

 <footer>
        <div class="footer-container">
            <div class="footer-links">
                <a href="#">About</a>
                <a href="#">Privacy Policy</a>
                <a href="#">Terms of Service</a>
                <a href="#">Contact</a>
            </div>
            <div class="copyright">
                &copy; <?= date('Y') ?> School Management System. All rights reserved.
            </div>
        </div>
    </footer>
<style>
  footer {
            background: #142a47;
            color: white;
            padding: 1.5rem 2rem;
            text-align: center;
            margin-top: 3rem;
        }
        
        
        .footer-container {
            max-width: 1200px;
            margin: 0 auto;
        }
        
        .footer-links {
            display: flex;
            justify-content: center;
            gap: 1.5rem;
            margin-bottom: 1rem;
            flex-wrap: wrap;
        }
        
        .footer-links a {
            color: rgba(255, 255, 255, 0.7);
            text-decoration: none;
            transition: var(--transition);
        }
        
        .footer-links a:hover {
            color: white;
        }
        
        .copyright {
            font-size: 0.9rem;
            color: rgba(255, 255, 255, 0.5);
        }
</style>
<script src="<?= $basePath ?? '' ?>assets/js/global.js"></script>