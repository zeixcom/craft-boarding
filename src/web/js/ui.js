/**
 * UI Module for Boarding
 * Handles UI-related functionality like buttons and menus
 */
(function () {
  // Ensure Boarding namespace exists
  window.Boarding = window.Boarding || {};

  // Module-level variables for menu management
  let currentMenu = null;

  /**
   * Add a tours button to the CP header or sidebar
   */
  window.Boarding.addToursButton = function (tours) {
    try {
      const settings = window.boardingSettings || {
        buttonPosition: "header",
        buttonLabel: "Available Tours",
        defaultBehavior: "manual",
      };

      if (settings.buttonPosition === "none") {
        return;
      }

      removeExistingButton();

      const $button = createButton(settings);

      if (!addButtonToContainer($button, settings)) {
        return;
      }

      addClickHandler($button, tours || []);
    } catch (error) {
      Boarding.utils.log("Error adding tours button: " + error, "error");
    }
  };

  /**
   * Remove any existing tours button
   */
  function removeExistingButton() {
    const existingButton = document.querySelector(".boarding-tours-button");
    if (existingButton) {
      existingButton.remove();
    }
  }

  /**
   * Create a button element with the appropriate structure
   */
  function createButton() {
    const $button = document.createElement("div");
    $button.className = "boarding-tours-button";
    return $button;
  }

  /**
   * Add the button to the appropriate container based on settings
   * @returns {boolean} Whether the button was successfully added
   */
  function addButtonToContainer($button, settings) {
    if (settings.buttonPosition === "header") {
      return addButtonToHeader($button, settings);
    } else if (settings.buttonPosition === "sidebar") {
      return addButtonToSidebar($button, settings);
    }
    return false;
  }

  /**
   * Add button to the header
   */
  function addButtonToHeader($button, settings) {
    const $header = document.querySelector("#global-header .flex");

    if (!$header) {
      return false;
    }

    $button.innerHTML = `<button class="btn">${
      settings.buttonLabel || "Tours"
    }</button>`;
    $header.appendChild($button);
    return true;
  }

  /**
   * Add button to the sidebar
   */
  function addButtonToSidebar($button, settings) {
    const $sidebar = document.querySelector("#global-sidebar nav");

    if (!$sidebar) {
      return false;
    }

    $button.innerHTML = `
            <div class="nav-item">
                <a class="sidebar-action" href="#">
                    <span class="icon">
                        <svg width="24" height="24" viewBox="0 0 24 24">
                            <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 18c-4.41 0-8-3.59-8-8s3.59-8 8-8 8 3.59 8 8-3.59 8-8 8zm-1-13h2v6h-2zm0 8h2v2h-2z"/>
                        </svg>
                    </span>
                    <span class="label">${
                      settings.buttonLabel || "Tours"
                    }</span>
                </a>
            </div>
        `;
    $sidebar.appendChild($button);
    return true;
  }

  /**
   * Add click handler to the button
   */
  function addClickHandler($button, tours) {
    if ($button.parentNode) {
      $button.addEventListener("click", function (e) {
        e.preventDefault();
        e.stopPropagation();
        window.Boarding.showToursMenu(tours);
      });
      return true;
    } else {
      return false;
    }
  }

  /**
   * Show a menu of available tours
   */
  window.Boarding.showToursMenu = function (tours) {
    if (currentMenu) {
      currentMenu.remove();
      currentMenu = null;
      return;
    }

    const $menu = document.createElement("div");
    $menu.className = "boarding-tours-menu boarding-plugin";
    currentMenu = $menu;

    let menuHtml = '<div class="boarding-tours-menu-items">';

    if (!tours || tours.length === 0) {
      menuHtml +=
        '<div class="boarding-tour-item"><p>No tours available</p></div>';
    } else {
      tours.forEach((tour) => {
        const tourId = tour.tourId || tour.id;
        const isCompleted = isCompletedTour(tour);

        const completedIcon = isCompleted
          ? '<span class="completed-icon" title="Tour completed">✓</span>'
          : "";

        const tourStatus = isCompleted ? "completed" : "";

        let buttonText = "Start Tour";
        let buttonClass = "btn";

        if (isCompleted) {
          buttonText = "Restart Tour";
        }

        menuHtml += `
                    <div class="boarding-tour-item ${tourStatus}" data-tour-id="${tourId}">
                        <h3>${tour.name} ${completedIcon}</h3>
                        <p>${tour.description || ""}</p>
                        <button class="main-tour-btn ${buttonClass}" data-action="start">${buttonText}</button>
                    </div>
                `;
      });
    }

    menuHtml += "</div>";
    $menu.innerHTML = menuHtml;

    $menu
      .querySelectorAll(".boarding-tour-item .main-tour-btn")
      .forEach(($btn) => {
        $btn.addEventListener("click", function () {
          const tourId = this.closest(".boarding-tour-item").dataset.tourId;

          const tour = Boarding.utils.findTourById(tours, tourId);

          if (tour) {
            window.Boarding.startTour(tour);
            $menu.remove();
            currentMenu = null;
          } else {
            if (Craft.cp) {
              Craft.cp.displayError(
                "Could not find the requested tour. It may have been deleted or you may not have permission to view it."
              );
            }
            $menu.remove();
            currentMenu = null;
          }
        });
      });

    const $close = document.createElement("button");
    $close.className = "boarding-tours-menu-close";
    $close.innerHTML = "×";
    $close.addEventListener("click", function () {
      $menu.remove();
      currentMenu = null;
    });
    $menu.appendChild($close);

    document.body.appendChild($menu);

    const closeMenu = function (e) {
      if (currentMenu && !currentMenu.contains(e.target)) {
        const tourButton = document.querySelector(".boarding-tours-button");
        if (!tourButton || !tourButton.contains(e.target)) {
          currentMenu.remove();
          currentMenu = null;
          document.removeEventListener("click", closeMenu);
        }
      }
    };

    document.addEventListener("click", closeMenu);
  };

  /**
   * Helper function to determine if a tour is completed
   */
  function isCompletedTour(tour) {
    if (tour.completedBy && tour.completedBy.length) {
      const currentUserEmail = Craft.username;
      return tour.completedBy.some(
        (completion) => completion.username === currentUserEmail
      );
    }

    return false;
  }
})();
