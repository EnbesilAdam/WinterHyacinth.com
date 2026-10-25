// Sayfa Kaydırma Efekti (Navbar)
        window.addEventListener('scroll', () => {
            const nav = document.querySelector('.navbar');
            if (nav) {
                if (window.scrollY > 50) {
                    nav.style.boxShadow = '0 5px 20px rgba(0,0,0,0.5)';
                    nav.style.background = 'rgba(11, 14, 20, 0.98)';
                } else {
                    nav.style.boxShadow = 'none';
                    nav.style.background = 'rgba(11, 14, 20, 0.9)';
                }
            }
        });

        // Akıcı Kaydırma (Smooth Scroll)
        function smoothScrollTo(targetPosition, duration = 600) {
            const startPosition = window.pageYOffset || window.scrollY;
            const distance = targetPosition - startPosition;
            let startTime = null;

            function animation(currentTime) {
                if (startTime === null) startTime = currentTime;
                const timeElapsed = currentTime - startTime;
                const progress = Math.min(timeElapsed / duration, 1);

                const ease = progress < 0.5 
                    ? 2 * progress * progress 
                    : 1 - Math.pow(-2 * progress + 2, 2) / 2;

                window.scrollTo(0, startPosition + (distance * ease));

                if (timeElapsed < duration) {
                    requestAnimationFrame(animation);
                }
            }

            requestAnimationFrame(animation);
        }

        // Global Tıklama Yöneticisi
        document.addEventListener("click", (e) => {
            const anchor = e.target.closest("a.smooth-scroll");
            if (anchor) {
                const targetId = anchor.getAttribute("href");
                if (targetId && targetId.startsWith("#")) {
                    e.preventDefault();
                    const targetElement = document.querySelector(targetId);
                    if (targetElement) {
                        const navbarHeight = document.querySelector('.navbar')?.offsetHeight || 70;
                        const targetPosition = targetElement.getBoundingClientRect().top + (window.pageYOffset || window.scrollY) - navbarHeight;
                        smoothScrollTo(targetPosition, 600);
                        return;
                    }
                }
            }

            const link = e.target.closest("a");
            if (link) {
                const href = link.getAttribute("href");
                const target = link.getAttribute("target");

                if (href && !href.startsWith("#") && target !== "_blank" && !href.includes("mailto:") && href !== "#") {
                    e.preventDefault();
                    
                    const loader = document.getElementById('page-loader');
                    if (loader) {
                        loader.classList.add('show');
                        setTimeout(() => {
                            window.location.href = href;
                        }, 500);
                    } else {
                        window.location.href = href;
                    }
                }
            }
        });

        // Sayfa Gösterildiğinde Görünürlüğü Aç (Siyah Kalma Sorununu Çözen Kısım)
        window.addEventListener("pageshow", () => {
            const loader = document.getElementById('page-loader');
            if (loader) {
                loader.classList.remove('show');
            }
            document.body.classList.add('body-visible');
        });

        // Lightbox Mantığı
        document.addEventListener("DOMContentLoaded", () => {
            const images = document.querySelectorAll(".screenshot-grid img");
            const modal = document.getElementById("imgModal");
            const modalImg = document.getElementById("modalImage");
            const closeBtn = document.querySelector(".close-modal");

            if (modal && modalImg) {
                images.forEach(img => {
                    img.addEventListener("click", () => {
                        modal.classList.add("open", "active");
                        modalImg.src = img.src;
                    });
                });

                modal.addEventListener("click", (e) => {
                    if (e.target !== modalImg) {
                        closeModal();
                    }
                });
            }

            if (closeBtn) {
                closeBtn.addEventListener("click", closeModal);
            }

            document.addEventListener("keydown", (e) => {
                if (e.key === "Escape" && modal && (modal.classList.contains("open") || modal.classList.contains("active"))) {
                    closeModal();
                }
            });
        });

        function closeModal() {
            const modal = document.getElementById("imgModal");
            if (modal) {
                modal.classList.remove("open", "active");
            }
        }