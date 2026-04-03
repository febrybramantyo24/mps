/*=== Javascript function indexing hear===========

1.counterUp ----------(Its use for counting number)
2.stickyHeader -------(header class sticky)
3.wowActive ----------( Waw js plugins activation)
4.swiperJs -----------(All swiper in this website hear)
5.salActive ----------(Sal animation for card and all text)
6.textChanger --------(Text flip for banner section)
7.timeLine -----------(History Time line)
8.datePicker ---------(On click date calender)
9.timePicker ---------(On click time picker)
10.timeLineStory -----(History page time line)
11.vedioActivation----(Vedio activation)
12.searchOption ------(search open)
13.cartBarshow -------(Cart sode bar)
14.sideMenu ----------(Open side menu for desktop)
15.Back to top -------(back to top)
16.filterPrice -------(Price filtering)

==================================================*/

(function ($) {
  'use strict';

  var rtsJs = {
    m: function (e) {
      rtsJs.d();
      rtsJs.methods();
    },
    d: function (e) {
      this._window = $(window),
        this._document = $(document),
        this._body = $('body'),
        this._html = $('html')
    },
    methods: function (e) {
      // rtsJs.preloader();
      // This file is used across many pages; some pages don't load every plugin.
      // Keep the site functional by isolating optional plugin init failures.
      var safe = function (fn) {
        try { fn(); } catch (e) { }
      };
      safe(function () { rtsJs.countDown(); });
      safe(function () { rtsJs.filterPrice(); });
      safe(function () { rtsJs.galleryPopUp(); });
      safe(function () { rtsJs.galleryPopUpmag(); });
      safe(function () { rtsJs.timeLineStory(); });
      safe(function () { rtsJs.vedioActivation(); });
      safe(function () { rtsJs.odoMeter(); });
      safe(function () { rtsJs.searchOption(); });
      safe(function () { rtsJs.metismenu(); });
      safe(function () { rtsJs.swiperActive(); });
      safe(function () { rtsJs.wowActive(); });
      safe(function () { rtsJs.stickyHeader(); });
      safe(function () { rtsJs.backToTopInit(); });
      safe(function () { rtsJs.sideMenu(); });
      safe(function () { rtsJs.menuCurrentLink(); });
      safe(function () { rtsJs.imageSwipe(); });
      safe(function () { rtsJs.niceSelect(); });
      safe(function () { rtsJs.portfolioHOver(); });
      safe(function () { rtsJs.cartBarshow(); });
      safe(function () { rtsJs.smoothScroll(); });
      safe(function () { rtsJs.rtlToggle(); });
    },
    // preloader: function () {

    //   var preload = $("#elevate-load");

    //   if (preload.length) {
    //     window.addEventListener('load', function () {
    //       document.querySelector('#elevate-load').classList.add("loaded");
    //     });
    //   };

    // },
    smoothScroll: function (e) {
      $(document).on('click', '.onepage a[href^="#"]', function (event) {
        event.preventDefault();

        $('html, body').animate({
          scrollTop: $($.attr(this, 'href')).offset().top
        }, 2000);
      });
    },
    countDown: function () {

      let countDown = document.getElementsByClassName('countdown');
      if (countDown.length) {
        document.addEventListener("DOMContentLoaded", function () {
          // Get the countdown element and the end date from its attribute
          const countdownElement = document.getElementById("countdown");
          const endDate = countdownElement.getAttribute("data-end-date");
          const endTime = new Date(endDate).getTime();

          if (isNaN(endTime)) {
            document.querySelector(".timer-section").innerHTML = "Invalid end date!";
            return;
          }

          // Get references to the time unit elements
          const daysElement = document.getElementById("days");
          const hoursElement = document.getElementById("hours");
          const minutesElement = document.getElementById("minutes");
          const secondsElement = document.getElementById("seconds");

          // Update the countdown every second
          const countdownInterval = setInterval(() => {
            const currentTime = new Date().getTime();
            const timeDifference = endTime - currentTime;

            // Calculate days, hours, minutes, and seconds
            const days = Math.floor(timeDifference / (1000 * 60 * 60 * 24));
            const hours = Math.floor(
              (timeDifference % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60)
            );
            const minutes = Math.floor(
              (timeDifference % (1000 * 60 * 60)) / (1000 * 60)
            );
            const seconds = Math.floor((timeDifference % (1000 * 60)) / 1000);

            // Update the timer elements
            if (timeDifference > 0) {
              daysElement.textContent = days;
              hoursElement.textContent = hours;
              minutesElement.textContent = minutes;
              secondsElement.textContent = seconds;
            } else {
              // Clear the interval and display "Time's up" when countdown ends
              clearInterval(countdownInterval);
              document.querySelector(".timer-section").innerHTML = "Time's up!";
            }
          }, 1000);
        });
      }
    },
    filterPrice: function () {
      var filter_price = $('.filter-price');
      if (filter_price.length) {
        var lowerSlider = document.querySelector('#lower');
        var upperSlider = document.querySelector('#upper');

        document.querySelector('#two').value = upperSlider.value;
        document.querySelector('#one').value = lowerSlider.value;

        var lowerVal = parseInt(lowerSlider.value);
        var upperVal = parseInt(upperSlider.value);

        upperSlider.oninput = function () {
          lowerVal = parseInt(lowerSlider.value);
          upperVal = parseInt(upperSlider.value);

          if (upperVal < lowerVal + 4) {
            lowerSlider.value = upperVal - 4;
            if (lowerVal == lowerSlider.min) {
              upperSlider.value = 4;
            }
          }
          document.querySelector('#two').value = this.value
        };

        lowerSlider.oninput = function () {
          lowerVal = parseInt(lowerSlider.value);
          upperVal = parseInt(upperSlider.value);
          if (lowerVal > upperVal - 4) {
            upperSlider.value = lowerVal + 4;
            if (upperVal == upperSlider.max) {
              lowerSlider.value = parseInt(upperSlider.max) - 4;
            }
          }
          document.querySelector('#one').value = this.value
        };
      }
    },
    galleryPopUp: function (e) {
      // Gallery image hover
      $(".img-wrapper").hover(
        function () {
          $(this).find(".img-overlay").animate({ opacity: 1 }, 600);
        }, function () {
          $(this).find(".img-overlay").animate({ opacity: 0 }, 600);
        }
      );

      // Lightbox
      var $overlay = $('<div id="overlay"></div>');
      var $image = $("<img>");
      var $prevButton = $('<div id="prevButton"><i class="fa fa-chevron-left"></i></div>');
      var $nextButton = $('<div id="nextButton"><i class="fa fa-chevron-right"></i></div>');
      var $exitButton = $('<div id="exitButton"><i class="fa fa-times"></i></div>');

      // Add overlay
      $overlay.append($image).prepend($prevButton).append($nextButton).append($exitButton);
      $("#gallery").append($overlay);

      // Hide overlay on default
      $overlay.hide();

      // When an image is clicked
      $(".img-overlay").click(function (event) {
        // Prevents default behavior
        event.preventDefault();
        // Adds href attribute to variable
        var imageLocation = $(this).prev().attr("href");
        // Add the image src to $image
        $image.attr("src", imageLocation);
        // Fade in the overlay
        $overlay.fadeIn("slow");
      });

      // When the overlay is clicked
      $overlay.click(function () {
        // Fade out the overlay
        $(this).fadeOut("slow");
      });

      // When next button is clicked
      $nextButton.click(function (event) {
        // Hide the current image
        $("#overlay img").hide();
        // Overlay image location
        var $currentImgSrc = $("#overlay img").attr("src");
        // Image with matching location of the overlay image
        var $currentImg = $('#image-gallery img[src="' + $currentImgSrc + '"]');
        // Finds the next image
        var $nextImg = $($currentImg.closest(".image").next().find("img"));
        // All of the images in the gallery
        var $images = $("#image-gallery img");
        // If there is a next image
        if ($nextImg.length > 0) {
          // Fade in the next image
          $("#overlay img").attr("src", $nextImg.attr("src")).fadeIn(800);
        } else {
          // Otherwise fade in the first image
          $("#overlay img").attr("src", $($images[0]).attr("src")).fadeIn(800);
        }
        // Prevents overlay from being hidden
        event.stopPropagation();
      });

      // When previous button is clicked
      $prevButton.click(function (event) {
        // Hide the current image
        $("#overlay img").hide();
        // Overlay image location
        var $currentImgSrc = $("#overlay img").attr("src");
        // Image with matching location of the overlay image
        var $currentImg = $('#image-gallery img[src="' + $currentImgSrc + '"]');
        // Finds the next image
        var $nextImg = $($currentImg.closest(".image").prev().find("img"));
        // Fade in the next image
        $("#overlay img").attr("src", $nextImg.attr("src")).fadeIn(800);
        // Prevents overlay from being hidden
        event.stopPropagation();
      });

      // When the exit button is clicked
      $exitButton.click(function () {
        // Fade out the overlay
        $("#overlay").fadeOut("slow");
      });
    },
    // story page timeline
    timeLineStory: function () {
      (function () {

        'use strict';

        // define variables
        var items = document.querySelectorAll(".timeline li");

        // check if an element is in viewport
        // http://stackoverflow.com/questions/123999/how-to-tell-if-a-dom-element-is-visible-in-the-current-viewport
        function isElementInViewport(el) {
          var rect = el.getBoundingClientRect();
          return (
            rect.top >= 0 &&
            rect.left >= 0 &&
            rect.bottom <= (window.innerHeight || document.documentElement.clientHeight) &&
            rect.right <= (window.innerWidth || document.documentElement.clientWidth)
          );
        }

        function callbackFunc() {
          for (var i = 0; i < items.length; i++) {
            if (isElementInViewport(items[i])) {
              items[i].classList.add("in-view");
            }
          }
        }

        // listen for events
        window.addEventListener("load", callbackFunc);
        window.addEventListener("resize", callbackFunc);
        window.addEventListener("scroll", callbackFunc);

      })();



    },
    vedioActivation: function (e) {
      $('#play-video, .play-video').on('click', function (e) {
        e.preventDefault();
        $('.video-overlay').addClass('open');
        $(".video-overlay").append('<iframe width="560" height="315" src="https://www.youtube.com/embed/Giek094C_l4" title="YouTube video player" frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" allowfullscreen></iframe>');
      });

      $('.video-overlay, .video-overlay-close').on('click', function (e) {
        e.preventDefault();
        close_video();
      });

      $(document).keyup(function (e) {
        if (e.keyCode === 27) {
          close_video();
        }
      });

      function close_video() {
        $('.video-overlay.open').removeClass('open').find('iframe').remove();
      };
    },
    odoMeter: function () {
      $(document).ready(function () {
        function isInViewport(element) {
          const rect = element.getBoundingClientRect();
          return (
            rect.top >= 0 &&
            rect.bottom <= (window.innerHeight || document.documentElement.clientHeight)
          );
        }

        function triggerOdometer(element) {
          const $element = $(element);
          if (!$element.hasClass('odometer-triggered')) {
            const countNumber = $element.attr('data-count');
            $element.html(countNumber);
            $element.addClass('odometer-triggered'); // Add a class to prevent re-triggering
          }
        }

        function handleOdometer() {
          $('.odometer').each(function () {
            if (isInViewport(this)) {
              triggerOdometer(this);
            }
          });
        }

        // Check on page load
        handleOdometer();

        // Check on scroll
        $(window).on('scroll', function () {
          handleOdometer();
        });
      });


    },

    // search popup
    searchOption: function () {
      $(document).on('click', '.search', function () {
        $(".search-input-area").addClass("show");
        $("#anywhere-home").addClass("bgshow");
      });
      $(document).on('click', '#close', function () {
        $(".search-input-area").removeClass("show");
        $("#anywhere-home").removeClass("bgshow");
      });
      $(document).on('click', '#anywhere-home', function () {
        $(".search-input-area").removeClass("show");
        $("#anywhere-home").removeClass("bgshow");
      });

    },
    metismenu: function () {
      if ($.fn && typeof $.fn.metisMenu === 'function') {
        $('#mobile-menu-active').metisMenu();
      }
    },

    swiperActive: function () {
      if (typeof Swiper === 'undefined') return;
      $(document).ready(function () {

        var sliderThumbnail = new Swiper(".slider-thumbnail", {
          spaceBetween: 30,
          slidesPerView: 3,
          freeMode: true,
          watchSlidesVisibility: true,
          watchSlidesProgress: true,
          breakpoints: {
            991: {
              spaceBetween: 30,
            },
            320: {
              spaceBetween: 15,
            }
          },
        });

        var swiper = new Swiper(".swiper-container-h12", {
          thumbs: {
            swiper: sliderThumbnail,
          },
        });

      });



      $(document).ready(function () {
        var swiper = new Swiper(".mySwiper-pd-slider", {
          speed: 1600,
          slidesPerView: 1,
          spaceBetween: 0,
          loop: true,
          autoplay: false,
          keyboard: {
            enabled: true,
            onlyInViewport: true
          },
          navigation: {
            nextEl: ".swiper-button-next",
            prevEl: ".swiper-button-prev",
          },
          autoplay: {
            delay: 2500,
            disableOnInteraction: false,
          },
        });
      });
      $(document).ready(function () {
        var swiper = new Swiper(".swiper-vision", {
          direction: "horizontal",
          effect: "slide",
          speed: 1600,
          slidesPerView: 1,
          spaceBetween: 0,
          slidesPerGroup: 1,
          centeredSlides: true,
          loop: true,
          autoplay: false,
          keyboard: {
            enabled: true,
            onlyInViewport: true
          },
          loopFillGroupWithBlank: true,
          pagination: {
            el: ".swiper-pagination",
            clickable: true,
            autoplay: false,
          },
        });
      });
      $(document).ready(function () {
        var swiper = new Swiper(".mySwiper-banner-one", {
          spaceBetween: 0,
          slidesPerView: 1,
          loop: true,
          speed: 1000,
          effect: "fade",
          pagination: {
            el: ".swiper-paginations",
            clickable: true,
            renderBullet: function (index, className) {
              return '<span class="' + className + '">' + "0" + (index + 1) + "</span>";
            },
          },
        });
      });
      $(document).ready(function () {
        var swiper = new Swiper(".mySwiper-banner-five", {
          spaceBetween: 0,
          slidesPerView: 1,
          loop: true,
          speed: 1000,
          // autoplay: {
          //   delay: 3000,
          //   disableOnInteraction: false,
          // },
          // effect: "fade",
          pagination: {
            el: ".swiper-pagination",
            clickable: true,
          },
        });
      });
      $(document).ready(function () {
        var swiper = new Swiper(".mySwiper-estimonias-inner", {
          spaceBetween: 0,
          slidesPerView: 1,
          loop: true,
          speed: 1000,
          navigation: {
            nextEl: ".swiper-button-next",
            prevEl: ".swiper-button-prev",
          },
        });
      });
      $(document).ready(function () {
        var swiper = new Swiper(".mySwiper-service-one", {
          spaceBetween: 30,
          slidesPerView: 3,
          loop: true,
          speed: 1000,
          // autoplay: {
          //   delay: 3000,
          //   disableOnInteraction: false,
          // },
          navigation: {
            nextEl: ".swiper-button-next",
            prevEl: ".swiper-button-prev",
          },
          pagination: {
            el: ".swiper-pagination",
            clickable: true,
          },
          breakpoints: {
            1500: {
              slidesPerView: 3,
            },
            1199: {
              slidesPerView: 3,
            },
            991: {
              slidesPerView: 2,
            },
            767: {
              slidesPerView: 2,
            },
            575: {
              slidesPerView: 1,
            },
            0: {
              slidesPerView: 1,
            }
          },
        });
      });
      $(document).ready(function () {
        var swiper = new Swiper(".mySwiper-case-5", {
          spaceBetween: 24,
          slidesPerView: 4,
          loop: true,
          speed: 1000,
          centeredSlides: true,
          navigation: {
            nextEl: ".swiper-button-next",
            prevEl: ".swiper-button-prev",
          },
          breakpoints: {
            1500: {
              slidesPerView: 2.6,
            },
            1199: {
              slidesPerView: 1.2,
            },
            991: {
              slidesPerView: 1.1,
            },
            767: {
              slidesPerView: 1.1,
            },
            575: {
              slidesPerView: 1,
            },
            0: {
              slidesPerView: 1,
            }
          },
        });
      });
      $(document).ready(function () {
        var swiper = new Swiper(".mySwiper-team-5", {
          spaceBetween: 24,
          slidesPerView: 4,
          loop: true,
          speed: 1000,
          navigation: {
            nextEl: ".swiper-button-next",
            prevEl: ".swiper-button-prev",
          },
          breakpoints: {
            1500: {
              slidesPerView: 4,
            },
            1199: {
              slidesPerView: 3,
            },
            991: {
              slidesPerView: 3,
            },
            767: {
              slidesPerView: 2,
            },
            575: {
              slidesPerView: 1,
            },
            0: {
              slidesPerView: 1,
            }
          },
        });
      });
      $(document).ready(function () {
        var swiper = new Swiper(".mySwiper-testimonails-5", {
          spaceBetween: 24,
          slidesPerView: 2,
          loop: true,
          speed: 1000,
          navigation: {
            nextEl: ".swiper-button-next",
            prevEl: ".swiper-button-prev",
          },
          breakpoints: {
            1500: {
              slidesPerView: 2,
            },
            1199: {
              slidesPerView: 2,
            },
            991: {
              slidesPerView: 2,
            },
            767: {
              slidesPerView: 1,
            },
            575: {
              slidesPerView: 1,
            },
            0: {
              slidesPerView: 1,
            }
          },
        });
      });
      $(document).ready(function () {
        var swiper = new Swiper(".mySwiper-portfolio-two", {
          spaceBetween: 24,
          slidesPerView: 2,
          loop: true,
          speed: 1000,
          navigation: {
            nextEl: ".swiper-button-next",
            prevEl: ".swiper-button-prev",
          },
          breakpoints: {
            1500: {
              slidesPerView: 2,
            },
            1199: {
              slidesPerView: 2,
            },
            991: {
              slidesPerView: 1,
            },
            767: {
              slidesPerView: 1,
            },
            575: {
              slidesPerView: 1,
            },
            0: {
              slidesPerView: 1,
            }
          },
        });
      });
      $(document).ready(function () {
        var swiper = new Swiper(".mySwiper-project-1", {
          spaceBetween: 40,
          slidesPerView: 2.5,
          loop: true,
          speed: 1000,
          centeredSlides: true,
          autoplay: {
            delay: 2500,
            disableOnInteraction: false,
          },
          breakpoints: {
            1500: {
              slidesPerView: 2.5,
            },
            1199: {
              slidesPerView: 3,
            },
            991: {
              slidesPerView: 1.5,
            },
            767: {
              slidesPerView: 1.5,
            },
            575: {
              slidesPerView: 1,
            },
            0: {
              slidesPerView: 1,
            }
          },
        });
      });
      $(document).ready(function () {
        var swiper = new Swiper(".mySwiper-brand-1", {
          spaceBetween: 103,
          slidesPerView: 6,
          loop: true,
          speed: 1000,
          autoplay: {
            delay: 2500,
            disableOnInteraction: false,
          },
          breakpoints: {
            1500: {
              slidesPerView: 6,
            },
            1199: {
              slidesPerView: 6,
            },
            991: {
              slidesPerView: 5,
            },
            767: {
              slidesPerView: 4,
            },
            575: {
              slidesPerView: 3,
            },
            0: {
              slidesPerView: 2,
            }
          },
        });
      });
      $(document).ready(function () {
        var swiper = new Swiper(".mySwiper-testimonails", {
          spaceBetween: 24,
          slidesPerView: 2,
          loop: true,
          speed: 1000,
          // autoplay: {
          //   delay: 2500,
          //   disableOnInteraction: false,
          // },
          navigation: {
            nextEl: ".swiper-button-next",
            prevEl: ".swiper-button-prev",
          },
          breakpoints: {
            1500: {
              slidesPerView: 2,
            },
            1199: {
              slidesPerView: 2,
            },
            991: {
              slidesPerView: 2,
            },
            767: {
              slidesPerView: 1,
            },
            575: {
              slidesPerView: 1,
            },
            0: {
              slidesPerView: 1,
            }
          },
        });
      });
      $(document).ready(function () {
        var swiper = new Swiper(".mySwiper-banner2", {
          spaceBetween: 0,
          slidesPerView: 1,
          loop: true,
          speed: 1500,
          effect: "fade",
          autoplay: {
            delay: 4500,
            disableOnInteraction: false,
          },
          pagination: {
            el: ".swiper-pagination",
            clickable: true,
          },
        });
      });
      $(document).ready(function () {
        var swiper = new Swiper(".mySwiper-banner-factrory", {
          spaceBetween: 0,
          slidesPerView: 1,
          loop: true,
          speed: 1500,
          effect: "fade",
          autoplay: {
            delay: 4500,
            disableOnInteraction: false,
          },
          pagination: {
            el: ".swiper-pagination",
            clickable: true,
          },
          navigation: {
            nextEl: ".swiper-button-next",
            prevEl: ".swiper-button-prev",
          },
        });
      });
      $(document).ready(function () {
        var swiper = new Swiper(".mySwiper-testimonails-3", {
          spaceBetween: 0,
          slidesPerView: 1,
          loop: true,
          speed: 1000,
          autoplay: {
            delay: 3000,
            disableOnInteraction: false,
          },
          navigation: {
            nextEl: ".swiper-button-next",
            prevEl: ".swiper-button-prev",
          },
        });
      });
      $(document).ready(function () {
        var swiper = new Swiper(".mySwiper-testimonails-4", {
          spaceBetween: 24,
          slidesPerView: 2,
          loop: true,
          speed: 1000,
          pagination: {
            el: ".swiper-pagination",
            clickable: true,
          },
          breakpoints: {
            1500: {
              slidesPerView: 2,
            },
            1199: {
              slidesPerView: 2,
            },
            991: {
              slidesPerView: 1,
            },
            767: {
              slidesPerView: 1,
            },
            575: {
              slidesPerView: 1,
            },
            0: {
              slidesPerView: 1,
            }
          },
        });
      });
      $(document).ready(function () {
        var swiper_1 = new Swiper(".mySwiper-banner-four", {
          spaceBetween: 0,
          slidesPerView: 1,
          loop: true,
          speed: 500,
          // autoplay: {
          //   delay: 4000,
          //   disableOnInteraction: false,
          // },
          // effect: "fade",
          pagination: {
            el: '.swiper-pagination', // For bullet pagination
            type: 'bullets',
            clickable: true, // Allows clicking on the bullets
          },

        });
        var swiper_thumb = new Swiper(".mySwiper-thumbnail", {
          spaceBetween: 20,
          slidesPerView: 2,
          direction: "vertical",
          loop: true,
          speed: 500,
          // autoplay: {
          //   delay: 4000,
          //   disableOnInteraction: false,
          // },
          // effect: "fade",
          navigation: {
            nextEl: ".swiper-button-next",
            prevEl: ".swiper-button-prev",
          },
        });

        swiper_1.controller.control = swiper_thumb;
        swiper_thumb.controller.control = swiper_1;

      });
      $(document).ready(function () {
        var swiper = new Swiper(".mySwiper-team-4", {
          spaceBetween: 24,
          slidesPerView: 4,
          loop: true,
          speed: 1000,
          autoplay: {
            delay: 3000,
            disableOnInteraction: false,
          },
          // mousewheel: true,
          navigation: {
            nextEl: ".swiper-button-next",
            prevEl: ".swiper-button-prev",
          },
          pagination: {
            el: ".swiper-pagination",
            type: "progressbar",
          },
          breakpoints: {
            1500: {
              slidesPerView: 4,
            },
            1199: {
              slidesPerView: 3,
            },
            991: {
              slidesPerView: 3,
            },
            767: {
              slidesPerView: 2,
            },
            575: {
              slidesPerView: 1,
            },
            0: {
              slidesPerView: 1,
            }
          },
        });
      });
      $(document).ready(function () {
        var swiper_1 = new Swiper(".mySwiper-banner-three", {
          spaceBetween: 0,
          slidesPerView: 1,
          loop: true,
          speed: 500,
          autoplay: {
            delay: 4000,
            disableOnInteraction: false,
          },
          effect: "fade",
          pagination: {
            el: '.swiper-pagination', // For bullet pagination
            type: 'bullets',
            clickable: true, // Allows clicking on the bullets
          },

        });
        swiper_1.on('slideChange', function () {
          console.log('slider moved');
          var activeslide = swiper_1.realIndex;
          var totalslide = swiper_1.slides.length;
          console.log(activeslide);
          $(".activeslide").html('0' + (activeslide + 1));
          $(".totalslide").html('0' + (totalslide));
        });
      });

    },

    wowActive: function () {
      new WOW().init();
    },

    stickyHeader: function (e) {
      $(window).scroll(function () {
        if ($(this).scrollTop() > 50) {
          $('.header--sticky').addClass('sticky')
        } else {
          $('.header--sticky').removeClass('sticky')
        }
      })
    },

    backToTopInit: function () {
      $(document).ready(function () {
        "use strict";

        var progressPath = document.querySelector('.progress-wrap path');
        var pathLength = progressPath.getTotalLength();
        progressPath.style.transition = progressPath.style.WebkitTransition = 'none';
        progressPath.style.strokeDasharray = pathLength + ' ' + pathLength;
        progressPath.style.strokeDashoffset = pathLength;
        progressPath.getBoundingClientRect();
        progressPath.style.transition = progressPath.style.WebkitTransition = 'stroke-dashoffset 10ms linear';
        var updateProgress = function () {
          var scroll = $(window).scrollTop();
          var height = $(document).height() - $(window).height();
          var progress = pathLength - (scroll * pathLength / height);
          progressPath.style.strokeDashoffset = progress;
        }
        updateProgress();
        $(window).scroll(updateProgress);
        var offset = 50;
        var duration = 550;
        jQuery(window).on('scroll', function () {
          if (jQuery(this).scrollTop() > offset) {
            jQuery('.progress-wrap').addClass('active-progress');
          } else {
            jQuery('.progress-wrap').removeClass('active-progress');
          }
        });
        jQuery('.progress-wrap').on('click', function (event) {
          event.preventDefault();
          jQuery('html, body').animate({ scrollTop: 0 }, duration);
          return false;
        })


      });
    },

    sideMenu: function () {

      // collups menu side right
      $(document).on('click', '.menu-btn', function () {
        $("#side-bar").addClass("show");
        $("#anywhere-home").addClass("bgshow");
      });
      $(document).on('click', '.close-icon-menu', function () {
        $("#side-bar").removeClass("show");
        $("#anywhere-home").removeClass("bgshow");
      });
      $(document).on('click', '#anywhere-home', function () {
        $("#side-bar").removeClass("show");
        $("#anywhere-home").removeClass("bgshow");
      });
      $(document).on('click', '.onepage .mainmenu li a', function () {
        $("#side-bar").removeClass("show");
        $("#anywhere-home").removeClass("bgshow");
      });
    },

    menuCurrentLink: function () {
      var currentPage = location.pathname.split("/"),
        current = currentPage[currentPage.length - 1];
      $('.parent-nav li > a').each(function () {
        var $this = $(this);
        if ($this.attr('href') === current) {
          $this.addClass('active');
          $this.parents('.has-dropdown').addClass('menu-item-open')
        }
      });
    },

    imageSwipe: function () {
      $(document).ready(function () {
        "use strict";


        var e = {
          init: function () {
            e.aosFunc();


          },

          isVariableDefined: function (el) {
            return typeof !!el && (el) != 'undefined' && el != null;
          },

          select: function (selectors) {
            return document.querySelector(selectors);
          },

          // START: 08 AOS Animation
          aosFunc: function () {
            var aos = e.select('.aos');
            if (e.isVariableDefined(aos)) {
              AOS.init({
                duration: 500,
                easing: 'ease-out-quart',
                once: true
              });
            }
          },
          // END: AOS Animation


        };
        e.init();
      });

    },

    niceSelect: function () {
      $(document).ready(function () {
        $('select:not([data-native-select])').niceSelect();
      });
    },

    portfolioHOver: function () {
      document.addEventListener('DOMContentLoaded', () => {
        // Get all project elements
        const projectElements = document.querySelectorAll('.single-project-3');
        // Get all image elements
        const imageElements = document.querySelectorAll('.thumbnail-portfolio-3 img');

        projectElements.forEach((project, index) => {
          project.addEventListener('mouseenter', () => {
            // Remove active class from all images
            imageElements.forEach(image => image.classList.remove('active'));
            // Add active class to the corresponding image
            if (imageElements[index]) {
              imageElements[index].classList.add('active');
            }
          });
        });
      });

    },

    cartBarshow: function () {
      // Cart Bar show & hide
      $(document).on('click', '.cart-icon', function () {
        $(".cart-bar").addClass("show");
        $("#anywhere-home").addClass("bgshow");
      });
      $(document).on('click', '.close-cart', function () {
        $(".cart-bar").removeClass("show");
        $("#anywhere-home").removeClass("bgshow");
      });
      $(document).on('click', '#anywhere-home', function () {
        $(".cart-bar").removeClass("show");
        $("#anywhere-home").removeClass("bgshow");
      });



      $(function () {
        $(".button").on("click", function () {
          var $button = $(this);
          var $parent = $button.parent();
          var oldValue = $parent.find('.input').val();

          if ($button.text() == "+") {
            var newVal = parseFloat(oldValue) + 1;
          } else {
            // Don't allow decrementing below zero
            if (oldValue > 1) {
              var newVal = parseFloat(oldValue) - 1;
            } else {
              newVal = 1;
            }
          }
          $parent.find('a.add-to-cart').attr('data-quantity', newVal);
          $parent.find('.input').val(newVal);
        });
      });

    },

    galleryPopUpmag: function () {
      $('.gallery-image').magnificPopup({
        type: 'image',
        gallery: {
          enabled: true
        }
      });
    },

    rtlToggle: function () {

      $(document).ready(function () {
        // Retrieve the saved direction from localStorage
        const savedDir = localStorage.getItem("pageDirection") || "ltr"; // Default to "ltr"
        $("body").attr("dir", savedDir);

        // Update button visibility based on saved direction
        if (savedDir === "rtl") {
          $(".rtl").removeClass("show");
          $(".ltr").addClass("show");
        } else {
          $(".rtl").addClass("show");
          $(".ltr").removeClass("show");
        }

        // Toggle direction and save state on button click
        $(".rtl-ltr-switcher-btn").on("click", function () {
          const currentDir = $("body").attr("dir");
          const newDir = currentDir === "rtl" ? "ltr" : "rtl";

          // Update body direction
          $("body").attr("dir", newDir);

          // Toggle button visibility
          $(".rtl").toggleClass("show");
          $(".ltr").toggleClass("show");

          // Save the new direction in localStorage
          localStorage.setItem("pageDirection", newDir);
        });
      });

    },

  }

  rtsJs.m();
})(jQuery, window)

;(function () {
  function normalizePath(path) {
    var value = String(path || '').trim();
    if (!value) return '/';
    var pure = value.split('?')[0].split('#')[0];
    if (!pure.startsWith('/')) pure = '/' + pure;
    if (pure.length > 1) pure = pure.replace(/\/+$/, '');
    return pure || '/';
  }

  function isSectionActive(currentPath, linkPath) {
    if (linkPath === '/') return currentPath === '/';
    return currentPath === linkPath || currentPath.indexOf(linkPath + '/') === 0;
  }

  function markActiveMenus() {
    var currentPath = normalizePath(window.location.pathname);
    var menuLinks = document.querySelectorAll('.nav-area a, #mobile-menu-active a.main');

    menuLinks.forEach(function (link) {
      var href = link.getAttribute('href') || '';
      if (!href || href.indexOf('http') === 0 || href.indexOf('mailto:') === 0 || href.indexOf('tel:') === 0) {
        return;
      }
      var linkPath = normalizePath(href);
      if (isSectionActive(currentPath, linkPath)) {
        link.classList.add('is-active');
        link.setAttribute('aria-current', 'page');
      } else {
        link.classList.remove('is-active');
        link.removeAttribute('aria-current');
      }
    });
  }

  function simplifyMobileMenu() {
    var mobileMenu = document.getElementById("mobile-menu-active");
    if (!mobileMenu) return;

    mobileMenu.innerHTML = [
      '<li><a href="/" class="main">Home</a></li>',
      '<li><a href="/layanan/" class="main">Layanan</a></li>',
      '<li><a href="/produk/" class="main">Produk</a></li>',
      '<li><a href="/proyek/" class="main">Proyek</a></li>',
      '<li><a href="/artikel/" class="main">Artikel</a></li>',
      '<li><a href="/kontak/" class="main">Kontak</a></li>',
      '<li><a href="/tentang/" class="main">Tentang</a></li>'
    ].join("");

    markActiveMenus();
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", simplifyMobileMenu);
  } else {
    simplifyMobileMenu();
  }

  window.addEventListener('pageshow', markActiveMenus);
})();

;(function () {
  function normalizeUrl(value) {
    var raw = String(value || '').trim();
    if (!raw) return '';
    if (/^https?:\/\//i.test(raw)) return raw;
    if (/^\/\//.test(raw)) return 'https:' + raw;
    return 'https://' + raw;
  }

  function escapeHtml(value) {
    return String(value || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function normalizePhoneHref(value) {
    var raw = String(value || '').trim();
    if (!raw) return '';
    var clean = raw.replace(/[^\d+]/g, '');
    if (!clean) return '';
    return 'tel:' + clean;
  }

  function normalizeMailHref(value) {
    var raw = String(value || '').trim();
    if (!raw) return '';
    return 'mailto:' + raw;
  }

  function ensureAnchors(box, minCount) {
    var anchors = box.querySelectorAll('a');
    while (anchors.length < minCount) {
      var a = document.createElement('a');
      a.href = '#';
      box.appendChild(a);
      anchors = box.querySelectorAll('a');
    }
    return anchors;
  }

  function setAnchorValue(anchor, text, href, asHtml) {
    if (!anchor) return;
    var value = String(text || '').trim();
    if (!value) {
      anchor.textContent = '';
      anchor.removeAttribute('href');
      anchor.style.display = 'none';
      return;
    }
    anchor.style.display = '';
    if (asHtml) {
      anchor.innerHTML = value;
    } else {
      anchor.textContent = value;
    }
    if (href) {
      anchor.setAttribute('href', href);
    } else {
      anchor.setAttribute('href', '#');
    }
  }

  function clearLegacyFallbacks() {
    // Kept intentionally as a hook for future migration scripts.
  }

  function getPlatformFromIcon(anchor) {
    var icon = anchor.querySelector('i');
    var className = icon ? icon.className : '';
    if (className.indexOf('fa-facebook') !== -1) return 'facebook';
    if (className.indexOf('fa-twitter') !== -1 || className.indexOf('fa-x-twitter') !== -1) return 'twitter';
    if (className.indexOf('fa-instagram') !== -1) return 'instagram';
    if (className.indexOf('fa-youtube') !== -1) return 'youtube';
    if (className.indexOf('fa-linkedin') !== -1) return 'linkedin';
    if (className.indexOf('fa-whatsapp') !== -1) return 'whatsapp';
    return '';
  }

  function applySocialSettings(settings) {
    var socialLinks = {
      facebook: normalizeUrl(settings.facebook),
      twitter: normalizeUrl(settings.twitter),
      instagram: normalizeUrl(settings.instagram),
      youtube: normalizeUrl(settings.youtube),
      linkedin: normalizeUrl(settings.linkedin),
      whatsapp: normalizeUrl(settings.whatsapp)
    };

    function buildWhatsappLink(baseUrl, message) {
      var base = String(baseUrl || '').trim();
      var text = String(message || '').trim();
      if (!base) return '';
      if (!text) return base;
      // Don't double-apply if the URL already contains a text param.
      if (/[?&]text=/.test(base)) return base;
      var sep = base.indexOf('?') === -1 ? '?' : '&';
      return base + sep + 'text=' + encodeURIComponent(text);
    }

    var iconAnchors = document.querySelectorAll(
      '.social-header ul a, .social-area-wrapper-one ul a, .social-wrapper-one ul a'
    );

    Array.prototype.forEach.call(iconAnchors, function (anchor) {
      var platform = getPlatformFromIcon(anchor);
      var targetUrl = socialLinks[platform] || '';
      if (!platform) return;
      if (!targetUrl) {
        anchor.removeAttribute('href');
        anchor.style.display = 'none';
        return;
      }
      anchor.style.display = '';
      anchor.setAttribute('href', targetUrl);
      anchor.setAttribute('target', '_blank');
      anchor.setAttribute('rel', 'noopener');
    });

    // Templated WA links (e.g. project/service specific).
    var templatedWa = document.querySelectorAll('a[data-wa-message]');
    Array.prototype.forEach.call(templatedWa, function (anchor) {
      if (!socialLinks.whatsapp) {
        anchor.removeAttribute('href');
        anchor.style.display = 'none';
        return;
      }
      var msg = anchor.getAttribute('data-wa-message') || '';
      var target = buildWhatsappLink(socialLinks.whatsapp, msg);
      anchor.style.display = '';
      anchor.setAttribute('href', target);
      anchor.setAttribute('target', '_blank');
      anchor.setAttribute('rel', 'noopener');
    });

    var waButtons = document.querySelectorAll('a');
    Array.prototype.forEach.call(waButtons, function (anchor) {
      if (anchor && anchor.hasAttribute && anchor.hasAttribute('data-wa-message')) return;
      var buttonText = String(anchor.textContent || '').toLowerCase();
      var hasWhatsappText = buttonText.indexOf('whatsapp') !== -1;
      var hasWaText = /\bwa\b/.test(buttonText);
      if (!hasWhatsappText && !hasWaText) return;
      if (!socialLinks.whatsapp) {
        anchor.removeAttribute('href');
        anchor.style.display = 'none';
        return;
      }
      anchor.style.display = '';
      anchor.setAttribute('href', socialLinks.whatsapp);
      anchor.setAttribute('target', '_blank');
      anchor.setAttribute('rel', 'noopener');
    });
  }

  function applyHeaderTopSettings(settings) {
    var phonePrimary = String(settings.headerPhonePrimary || settings.footerPhonePrimary || '').trim();
    var phoneSecondary = String(settings.headerPhoneSecondary || settings.footerPhoneSecondary || '').trim();
    var emailPrimary = String(settings.headerEmailPrimary || settings.footerSupportEmailPrimary || '').trim();
    var headerLeft = document.querySelector('.header-top-wrapper .left');
    if (!headerLeft) return;

    var callBlocks = headerLeft.querySelectorAll('.call');
    Array.prototype.forEach.call(callBlocks, function (block) {
      var icon = block.querySelector('i');
      var className = icon ? icon.className : '';
      if (className.indexOf('fa-mobile') !== -1 || className.indexOf('fa-phone') !== -1) {
        var phoneAnchors = ensureAnchors(block, 2);
        setAnchorValue(phoneAnchors[0], phonePrimary, normalizePhoneHref(phonePrimary), false);
        setAnchorValue(phoneAnchors[1], phoneSecondary, normalizePhoneHref(phoneSecondary), false);
      }
      if (className.indexOf('fa-envelope') !== -1) {
        var mailAnchors = ensureAnchors(block, 1);
        setAnchorValue(mailAnchors[0], emailPrimary, normalizeMailHref(emailPrimary), false);
      }
    });
  }

  function applyFooterContactSettings(settings) {
    var footerPhonePrimary = String(settings.footerPhonePrimary || '').trim();
    var footerPhoneSecondary = String(settings.footerPhoneSecondary || '').trim();
    var footerHours1 = String(settings.footerOfficeHours1 || '').trim();
    var footerHours2 = String(settings.footerOfficeHours2 || '').trim();
    var footerEmail1 = String(settings.footerSupportEmailPrimary || '').trim();
    var footerEmail2 = String(settings.footerSupportEmailSecondary || '').trim();
    var footerAddress1 = String(settings.footerAddress1 || '').trim();
    var footerAddress2 = String(settings.footerAddress2 || '').trim();
    var ctaHoursEl = document.querySelector('.small-cta-area .cta-small-left span');
    var ctaHoursLines = [footerHours1, footerHours2].filter(function (line) { return line !== ''; });
    if (ctaHoursEl && ctaHoursLines.length) {
      ctaHoursEl.textContent = ctaHoursLines.join(' | ');
    }

    var footerBoxes = document.querySelectorAll('.contact-area-footer-top .single-contact-area-box');
    Array.prototype.forEach.call(footerBoxes, function (box) {
      var icon = box.querySelector('i');
      var className = icon ? icon.className : '';

      if (className.indexOf('fa-phone') !== -1) {
        var phoneAnchors = ensureAnchors(box, 2);
        setAnchorValue(phoneAnchors[0], footerPhonePrimary, normalizePhoneHref(footerPhonePrimary), false);
        setAnchorValue(phoneAnchors[1], footerPhoneSecondary, normalizePhoneHref(footerPhoneSecondary), false);
      }

      if (className.indexOf('fa-clock') !== -1) {
        var hoursAnchor = ensureAnchors(box, 1)[0];
        var hoursLines = [footerHours1, footerHours2].filter(function (line) { return line !== ''; });
        var hoursHtml = hoursLines.length
          ? escapeHtml(hoursLines.join('|||')).replace(/\|\|\|/g, '<br>')
          : '';
        setAnchorValue(hoursAnchor, hoursHtml, '#', true);
      }

      if (className.indexOf('fa-envelope') !== -1) {
        var mailAnchors = ensureAnchors(box, 2);
        setAnchorValue(mailAnchors[0], footerEmail1, normalizeMailHref(footerEmail1), false);
        setAnchorValue(mailAnchors[1], footerEmail2, normalizeMailHref(footerEmail2), false);
      }

      if (className.indexOf('fa-location-dot') !== -1) {
        var addressAnchor = ensureAnchors(box, 1)[0];
        var addressLines = [footerAddress1, footerAddress2].filter(function (line) { return line !== ''; });
        var addressHtml = addressLines.length
          ? escapeHtml(addressLines.join('|||')).replace(/\|\|\|/g, '<br>')
          : '';
        setAnchorValue(addressAnchor, addressHtml, '#', true);
      }
    });
  }

  function applyContactPageSectionSettings(settings) {
    var wrapper = document.querySelector('.rts-contact-area-page .contact-main-wrapper-left');
    if (!wrapper) return;

    var pretitle = String(settings.contactSectionPretitle || '').trim();
    var title = String(settings.contactSectionTitle || '').trim();
    var description = String(settings.contactSectionDescription || '').trim();
    var callTitle = String(settings.contactCardCallTitle || '').trim();
    var officeTitle = String(settings.contactCardOfficeTitle || '').trim();
    var phonePrimary = String(settings.footerPhonePrimary || settings.headerPhonePrimary || '').trim();
    var phoneSecondary = String(settings.footerPhoneSecondary || settings.headerPhoneSecondary || '').trim();
    var address1 = String(settings.footerAddress1 || '').trim();
    var address2 = String(settings.footerAddress2 || '').trim();

    var pretitleEl = wrapper.querySelector(':scope > span');
    var titleEl = wrapper.querySelector('.title-main');
    var descEl = wrapper.querySelector('.disc');
    if (pretitleEl && pretitle) pretitleEl.textContent = pretitle;
    if (titleEl && title) titleEl.textContent = title;
    if (descEl) {
      if (description) {
        descEl.textContent = description;
      } else {
        var descLegacy = String(descEl.textContent || '').replace(/\s+/g, ' ').trim();
        if (/Pacific hake false trevally queen parrotfish/i.test(descLegacy)) {
          descEl.textContent = '';
          descEl.style.display = 'none';
        }
      }
    }

    var cards = wrapper.querySelectorAll('.quick-contact-page-1');
    if (cards[0]) {
      var callTitleEl = cards[0].querySelector('.title');
      var callContentEl = cards[0].querySelector('p');
      if (callTitleEl && callTitle) callTitleEl.textContent = callTitle;
      if (callContentEl) {
        var lines = [phonePrimary, phoneSecondary].filter(function (item) { return item !== ''; });
        if (lines.length) {
          callContentEl.innerHTML = escapeHtml(lines.join('|||')).replace(/\|\|\|/g, '<br>');
        } else {
          callContentEl.textContent = '';
          callContentEl.style.display = 'none';
        }
      }
    }
    if (cards[1]) {
      var officeTitleEl = cards[1].querySelector('.title');
      var officeContentEl = cards[1].querySelector('p');
      if (officeTitleEl && officeTitle) officeTitleEl.textContent = officeTitle;
      if (officeContentEl) {
        var officeLines = [address1, address2].filter(function (item) { return item !== ''; });
        if (officeLines.length) {
          officeContentEl.innerHTML = escapeHtml(officeLines.join('|||')).replace(/\|\|\|/g, '<br>');
        } else {
          officeContentEl.textContent = '';
          officeContentEl.style.display = 'none';
        }
      }
    }
  }

  function applyGlobalMenuVisibility(settings) {
    if (!settings || typeof settings !== 'object') return;

    var pick = function (obj, camelKey, snakeKey) {
      if (!obj) return undefined;
      if (obj[camelKey] !== undefined) return obj[camelKey];
      if (obj[snakeKey] !== undefined) return obj[snakeKey];
      return undefined;
    };
    var toBoolShow = function (value, fallback) {
      if (value === undefined || value === null || value === '') return fallback;
      var str = String(value).trim();
      if (str === '1') return true;
      if (str === '0') return false;
      return fallback;
    };

    var toggleMenuItem = function (href, show) {
      var selector = '.nav-area .main-nav > a[href="' + href + '"], #mobile-menu-active a.main[href="' + href + '"], .mobile-nav-links a[href="' + href + '"]';
      document.querySelectorAll(selector).forEach(function (anchor) {
        var item = anchor.closest('li');
        if (item) {
          item.style.display = show ? '' : 'none';
        } else {
          anchor.style.display = show ? '' : 'none';
        }
      });
    };

    var showLayanan = toBoolShow(pick(settings, 'showMenuLayanan', 'show_menu_layanan'), true);
    var showProduk = toBoolShow(pick(settings, 'showMenuProduk', 'show_menu_produk'), true);
    var showProyek = toBoolShow(pick(settings, 'showMenuProyek', 'show_menu_proyek'), true);
    var showArtikel = toBoolShow(pick(settings, 'showMenuArtikel', 'show_menu_artikel'), true);
    var showKontak = toBoolShow(pick(settings, 'showMenuKontak', 'show_menu_kontak'), true);
    var showTentang = toBoolShow(pick(settings, 'showMenuTentang', 'show_menu_tentang'), true);

    // Build the menu from settings instead of relying on static HTML.
    var pathname = (window.location && window.location.pathname) ? window.location.pathname : '/';
    var isActiveHref = function (href) {
      if (!href) return false;
      if (href === '/') return pathname === '/' || pathname === '';
      return pathname.indexOf(href) === 0;
    };

    var menu = [
      { href: '/', label: 'Home', show: true },
      { href: '/layanan/', label: 'Layanan', show: showLayanan },
      { href: '/produk/', label: 'Produk', show: showProduk },
      { href: '/proyek/', label: 'Proyek', show: showProyek },
      { href: '/artikel/', label: 'Artikel', show: showArtikel },
      { href: '/kontak/', label: 'Kontak', show: showKontak },
      { href: '/tentang/', label: 'Tentang', show: showTentang }
    ];

    var visible = menu.filter(function (item) { return item.show; });
    var desktopHtml = visible.map(function (item) {
      var activeClass = isActiveHref(item.href) ? ' active' : '';
      return '<li class="main-nav' + activeClass + '"><a href="' + item.href + '">' + item.label + '</a></li>';
    }).join('');
    var mobileHtml = visible.map(function (item) {
      var activeClass = isActiveHref(item.href) ? ' active' : '';
      return '<li class="' + activeClass.trim() + '"><a href="' + item.href + '" class="main">' + item.label + '</a></li>';
    }).join('');

    var desktopUls = document.querySelectorAll('.nav-area ul');
    Array.prototype.forEach.call(desktopUls, function (ul) {
      ul.innerHTML = desktopHtml;
    });

    var mobileMenu = document.getElementById('mobile-menu-active');
    if (mobileMenu) {
      mobileMenu.innerHTML = mobileHtml;
      if (window.jQuery && window.jQuery.fn && typeof window.jQuery.fn.metisMenu === 'function') {
        try {
          window.jQuery(mobileMenu).metisMenu();
        } catch (e) {}
      }
    }

    // Home page uses this flag to decide whether to fetch/render articles.
    var homeArticlesSection = document.getElementById('home-articles-section');
    if (homeArticlesSection) {
      homeArticlesSection.style.display = showArtikel ? '' : 'none';
      window.__homeShowArticlesSection = showArtikel;
    }
  }

  function bootDynamicSocialSettings() {
    clearLegacyFallbacks();

    // Render default menus immediately (before fetching settings) to avoid empty nav on first paint.
    applyGlobalMenuVisibility({});

    var cacheKey = 'mps_site_settings_cache_v1';
    var readCachedSettings = function () {
      try {
        if (!window.localStorage) return null;
        var raw = window.localStorage.getItem(cacheKey);
        if (!raw) return null;
        var parsed = JSON.parse(raw);
        if (parsed && parsed.settings && typeof parsed.settings === 'object') {
          return parsed.settings;
        }
        if (parsed && typeof parsed === 'object') {
          // Back-compat: cached the settings object directly.
          return parsed;
        }
        return null;
      } catch (e) {
        return null;
      }
    };
    var writeCachedSettings = function (settings) {
      try {
        if (!window.localStorage) return;
        window.localStorage.setItem(cacheKey, JSON.stringify({
          ts: Date.now(),
          settings: settings
        }));
      } catch (e) {}
    };

    // Apply cached menu visibility early so behavior is consistent across pages
    // even when the settings endpoint is temporarily unavailable.
    var cached = readCachedSettings();
    if (cached) {
      applyGlobalMenuVisibility(cached);
    }

    fetch('/api/site-settings.php', { cache: 'no-store' })
      .then(function (res) { return res.json(); })
      .then(function (payload) {
        if (!payload || !payload.ok || !payload.settings) return;
        writeCachedSettings(payload.settings);
        applySocialSettings(payload.settings);
        applyHeaderTopSettings(payload.settings);
        applyFooterContactSettings(payload.settings);
        applyContactPageSectionSettings(payload.settings);
        applyGlobalMenuVisibility(payload.settings);
      })
      .catch(function () {
        // Keep last cached values (if any) when API unavailable.
      });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bootDynamicSocialSettings);
  } else {
    bootDynamicSocialSettings();
  }
})();


