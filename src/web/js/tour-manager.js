/**
 * Tour Manager Module for Boarding
 * Handles tour loading, initialization and management
 */
(function () {
  // Ensure Boarding namespace exists
  window.Boarding = window.Boarding || {};

  /**
   * Initialize tours for the current page
   */
  window.Boarding.initTours = function () {
    // Don't initialize more than once
    if (Boarding.isInitialized && Boarding.isInitialized()) {
      return;
    }

    Boarding.setInitialized(true);

    if (typeof Shepherd === "undefined") {
      return;
    }

    const storedTourState = localStorage.getItem("boarding-current-tour");
    const urlParams = new URLSearchParams(window.location.search);
    const autoStartTourId = urlParams.get("startTour");

    const settings = window.boardingSettings || {
      buttonPosition: "header",
      defaultBehavior: "manual",
      buttonLabel: "Available Tours",
    };

    const toursUrl = Craft.getActionUrl(
      "boarding/tours/get-tours-for-current-user"
    );

    fetch(toursUrl, {
      headers: {
        Accept: "application/json",
      },
      credentials: "same-origin",
    })
      .then((response) => {
        return response.json();
      })
      .then((data) => {
        window.Boarding.cachedTours = data.success ? data.tours || [] : [];

        if (settings.buttonPosition !== "none") {
          window.Boarding.addToursButton(data.tours);
        }

        if (
          data.success &&
          data.tours &&
          data.tours.length > 0 &&
          autoStartTourId
        ) {
          const tour = Boarding.utils.findTourById(data.tours, autoStartTourId);

          if (tour) {
            const newUrl =
              window.location.pathname +
              (window.location.search.replace(/[?&]startTour=[^&]+/, "") || "");
            window.history.replaceState({}, document.title, newUrl);

            localStorage.removeItem("boarding-current-tour");
            localStorage.removeItem("boarding-redirect-count");

            setTimeout(() => {
              window.Boarding.startTour(tour);
            }, 500);
          } else {
            if (Craft.cp) {
              Craft.cp.displayError(
                "Could not find the requested tour. Please check if the tour exists and you have permission to view it."
              );
            }
          }
        } else if (
          data.success &&
          data.tours &&
          data.tours.length > 0 &&
          storedTourState &&
          !autoStartTourId
        ) {
          try {
            const tourState = JSON.parse(storedTourState);

            if (tourState && tourState.tourId) {
              const tour = Boarding.utils.findTourById(
                data.tours,
                tourState.tourId
              );

              if (tour) {
                let startAt = 0;
                if (typeof tourState.nextStep === "number") {
                  startAt = tourState.nextStep;
                } else if (typeof tourState.currentStep === "number") {
                  startAt = tourState.currentStep;
                }

                if (startAt >= tour.steps.length) {
                  startAt = 0;
                }

                localStorage.removeItem("boarding-current-tour");

                setTimeout(() => {
                  window.Boarding.startTour(tour, startAt);
                }, 500);
              } else {
                localStorage.removeItem("boarding-current-tour");
              }
            }
          } catch (error) {
            localStorage.removeItem("boarding-current-tour");
          }
        } else if (
          data.success &&
          data.tours &&
          data.tours.length > 0 &&
          settings &&
          settings.defaultBehavior === "auto" &&
          !autoStartTourId &&
          !storedTourState
        ) {
          let tourToStart = null;

          if (!tourToStart) {
            tourToStart = data.tours.find((tour) => {
              const isCompleted = tour.completed === true ||
                (tour.completedBy &&
                  tour.completedBy.some(
                    (completion) => completion.username === Craft.username
                  ));
              return !isCompleted;
            });
          }

          if (tourToStart) {
            setTimeout(() => {
              window.Boarding.startTour(tourToStart);
            }, 500);
          }
        }
      })
      .catch((error) => {
        if (
          settings.buttonPosition !== "none" &&
          !document.querySelector(".boarding-tours-button")
        ) {
          window.Boarding.addToursButton([]);
        }
      });
  };

  /**
   * Mark a tour as completed
   */
  window.Boarding.markTourCompleted = function (tourId) {
    if (!tourId) {
      return;
    }

    const formData = new FormData();
    formData.append("tourId", tourId);

    const completionUrl = Craft.getActionUrl("boarding/tours/mark-completed");

    fetch(completionUrl, {
      method: "POST",
      headers: {
        "X-CSRF-Token": Craft.csrfTokenValue,
        "X-Requested-With": "XMLHttpRequest",
        Accept: "application/json",
      },
      body: formData,
    })
      .then((response) => {
        if (!response.ok) {
          throw new Error(`HTTP error! Status: ${response.status}`);
        }
        return response.json();
      })
      .then((data) => {
        if (data.success) {
          if (window.Boarding.cachedTours) {
            const tourIndex = window.Boarding.cachedTours.findIndex(
              (t) => t.tourId === tourId || t.id === tourId
            );

            if (tourIndex !== -1) {
              window.Boarding.cachedTours[tourIndex].completed = true;
            }
          }

          fetch(
            Craft.getActionUrl("boarding/tours/get-tours-for-current-user"),
            {
              headers: {
                Accept: "application/json",
                "X-Requested-With": "XMLHttpRequest",
                "X-CSRF-Token": Craft.csrfTokenValue,
              },
            }
          )
            .then((response) => response.json())
            .then((refreshedData) => {
              if (refreshedData.success && refreshedData.tours) {
                window.Boarding.cachedTours = refreshedData.tours;
              }
            })
            .catch((error) => {
              Craft.cp.displayError(
                "Error refreshing tours data after completion: " + error.message
              );
            });
        }
      })
      .catch((error) => {
        if (Craft.cp) {
          Craft.cp.displayError(
            "Error marking tour as completed: " + error.message
          );
        }
      });
  };

  /**
   * Load and start a tour by ID.
   * Can be used to start tours programmatically from anywhere.
   *
   * @param {string} tourId - The ID of the tour to load and start
   * @param {boolean} forceReload - Whether to force reload tours data before starting
   * @return {Promise} Promise resolving to the started tour or null
   */
  window.Boarding.loadTourById = function (tourId, forceReload = false) {
    if (!tourId) {
      return Promise.reject(new Error("Invalid tour ID"));
    }

    if (
      !forceReload &&
      window.Boarding.cachedTours &&
      window.Boarding.cachedTours.length > 0
    ) {
      const tour = Boarding.utils.findTourById(
        window.Boarding.cachedTours,
        tourId
      );
      if (tour) {
        const startedTour = window.Boarding.startTour(tour);
        return Promise.resolve(startedTour);
      }
    }

    return window.Boarding.reloadTours().then((reloadedTours) => {
      const tour = Boarding.utils.findTourById(reloadedTours, tourId);

      if (tour) {
        const startedTour = window.Boarding.startTour(tour);
        return startedTour;
      } else {
        throw new Error("Tour not found: " + tourId);
      }
    });
  };

  /**
   * Reload tours data from the server
   *
   * @return {Promise} Promise resolving to the tours data
   */
  window.Boarding.reloadTours = function () {
    return new Promise((resolve, reject) => {
      const toursUrl = Craft.getActionUrl(
        "boarding/tours/get-tours-for-current-user"
      );

      fetch(toursUrl, {
        headers: {
          Accept: "application/json",
          "X-Requested-With": "XMLHttpRequest",
          "X-CSRF-Token": Craft.csrfTokenValue,
        },
        credentials: "same-origin",
      })
        .then((response) => {
          if (!response.ok) {
            throw new Error(`HTTP error! Status: ${response.status}`);
          }
          return response.json();
        })
        .then((data) => {
          if (data.success) {
            window.Boarding.cachedTours = data.tours || [];
            resolve(data.tours || []);
          } else {
            const error = new Error(
              data.error || "Failed to reload tours data"
            );
            reject(error);
          }
        })
        .catch((error) => {
          reject(error);
        });
    });
  };
})();
