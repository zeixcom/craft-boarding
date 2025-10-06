/**
 * Boarding Core Module
 * Contains core functionality and namespace setup
 */
(function () {
  window.Boarding = window.Boarding || {};

  let isInitialized = false;

  let cachedTours = [];

  Boarding.isInitialized = function () {
    return isInitialized;
  };

  Boarding.setInitialized = function (value) {
    isInitialized = value;
  };

  Boarding.getCachedTours = function () {
    return cachedTours;
  };

  Boarding.setCachedTours = function (tours) {
    cachedTours = tours;
  };

  /**
   * Start a specific tour
   */
  window.Boarding.startTour = function (tourData, startAtStep = null) {
    if (!tourData || !tourData.steps || tourData.steps.length === 0) {
      return;
    }

    if (typeof Shepherd === "undefined") {
      return;
    }

    const tour = new Shepherd.Tour({
      defaultStepOptions: {
        cancelIcon: {
          enabled: true,
        },
        classes: "boarding-tours-step boarding-plugin",
        scrollTo: { behavior: "smooth", block: "center" },
        arrow: true,
      },
      useModalOverlay: true,
    });

    tour.on("start", () => {
      const overlayEl = document.querySelector(
        ".shepherd-modal-overlay-container"
      );
      if (overlayEl) {
        overlayEl.classList.add("boarding-plugin");
      }

      setTimeout(() => {
        document.querySelectorAll(".shepherd-element").forEach((el) => {
          if (!el.classList.contains("boarding-plugin")) {
            el.classList.add("boarding-plugin");
          }
        });
      }, 50);

      localStorage.setItem(
        "boarding-current-tour",
        JSON.stringify({
          tourId: tourData.id || tourData.tourId,
          currentStep: 0,
        })
      );
    });

    tour.on("show", (evt) => {
      document.querySelectorAll(".shepherd-element").forEach((el) => {
        if (!el.classList.contains("boarding-plugin")) {
          el.classList.add("boarding-plugin");
        }
      });

      const currentStepIndex = tour.steps.indexOf(evt.step);
      localStorage.setItem(
        "boarding-current-tour",
        JSON.stringify({
          tourId: tourData.id || tourData.tourId,
          currentStep: currentStepIndex,
        })
      );
    });

    tour.on("complete", () => {
      localStorage.removeItem("boarding-current-tour");

      const tourId = tourData.tourId || tourData.id;
      if (tourId) {
        window.Boarding.markTourCompleted(tourId);
      }

      if (Craft.cp) {
        Craft.cp.displayNotice("Tour completed successfully!");
      } else {
        Boarding.utils.log(
          "Craft.cp NOT available. Cannot display notice.",
          "error"
        );
      }
    });

    tour.on("cancel", function () {
      localStorage.removeItem("boarding-current-tour");
    });

    let progressPosition = "off";

    if (
      tourData.progressPosition &&
      ["off", "header", "footer"].includes(tourData.progressPosition)
    ) {
      progressPosition = tourData.progressPosition;
    }

    if (progressPosition !== "off") {
      const shepherdObserver = new MutationObserver((mutations) => {
        mutations.forEach((mutation) => {
          if (mutation.type === "childList" && mutation.addedNodes.length) {
            mutation.addedNodes.forEach((node) => {
              if (node.nodeType === Node.ELEMENT_NODE) {
                const stepElements =
                  node.classList && node.classList.contains("shepherd-element")
                    ? [node]
                    : node.querySelectorAll(".shepherd-element");

                if (stepElements.length) {
                  stepElements.forEach((stepElement) => {
                    const currentStep = tour.steps.find(
                      (step) => step.getElement() === stepElement
                    );
                    if (!currentStep) return;

                    const currentStepIndex = tour.steps.indexOf(currentStep);
                    const totalSteps = tour.steps.length;
                    const progressText = `${
                      currentStepIndex + 1
                    } of ${totalSteps}`;

                    if (progressPosition === "header") {
                      setTimeout(() => {
                        const header =
                          stepElement.querySelector(".shepherd-header");
                        if (header) {
                          if (
                            !header.querySelector(
                              ".boarding-progress-indicator"
                            )
                          ) {
                            const progress = document.createElement("span");
                            progress.className =
                              "boarding-progress-indicator boarding-header-progress boarding-plugin";
                            progress.textContent = progressText;

                            const cancelIcon = header.querySelector(
                              ".shepherd-cancel-icon"
                            );
                            if (cancelIcon) {
                              header.insertBefore(progress, cancelIcon);
                            } else {
                              header.appendChild(progress);
                            }
                          }
                        }
                      }, 10);
                    } else if (progressPosition === "footer") {
                      setTimeout(() => {
                        const footer =
                          stepElement.querySelector(".shepherd-footer");
                        if (footer) {
                          if (
                            !footer.querySelector(
                              ".boarding-progress-indicator"
                            )
                          ) {
                            const buttonsWrapper =
                              footer.querySelector(".shepherd-buttons");

                            const progress = document.createElement("div");
                            progress.className =
                              "boarding-progress-indicator boarding-footer-progress boarding-plugin";
                            progress.textContent = progressText;

                            if (buttonsWrapper) {
                              footer.insertBefore(progress, buttonsWrapper);
                            } else {
                              footer.appendChild(progress);
                            }
                          }
                        }
                      }, 10);
                    }
                  });
                }
              }
            });
          }
        });
      });

      shepherdObserver.observe(document.body, {
        childList: true,
        subtree: true,
      });

      tour.on("complete", () => {
        shepherdObserver.disconnect();
      });
    }

    let currentSiteId = null;
    if (Craft && Craft.siteId) {
      currentSiteId = parseInt(Craft.siteId, 10);
    } else if (Craft && Craft.currentSite && Craft.currentSite.id) {
      currentSiteId = parseInt(Craft.currentSite.id, 10);
    } else {
      const bodySiteId = document.body.getAttribute("data-site");
      if (bodySiteId) {
        currentSiteId = parseInt(bodySiteId, 10);
      }
    }

    const isTranslatable =
      tourData.translatable === true || tourData.translatable === 1;

    tourData.steps.forEach((stepData, index) => {
      if (!stepData || typeof stepData !== "object") {
        return;
      }

      let title = stepData.title || "";
      let text = stepData.text || stepData.content || "";
      let navigationButtonText = stepData.navigationButtonText || "Continue";

      if (
        isTranslatable &&
        currentSiteId &&
        stepData.translations &&
        typeof stepData.translations === "object" &&
        stepData.translations[currentSiteId]
      ) {
        try {
          const translation = stepData.translations[currentSiteId];

          if (translation.title && translation.title.trim() !== "") {
            title = translation.title;
          }

          if (translation.text && translation.text.trim() !== "") {
            text = translation.text;
          }

          if (
            stepData.type === "navigation" &&
            translation.navigationButtonText &&
            translation.navigationButtonText.trim() !== ""
          ) {
            navigationButtonText = translation.navigationButtonText;
          }
        } catch (e) {
          Boarding.utils.log(
            `Error processing translation for step ${index}: ${e.message}`,
            "error"
          );
        }
      }

      const step = {
        id: `step-${index}`,
        title: title,
        text: text,
        buttons: [],
      };

      if (stepData.attachTo && stepData.attachTo.element) {
        step.attachTo = {
          element: stepData.attachTo.element,
          on: stepData.attachTo.on || stepData.position || "bottom",
        };
      } else if (stepData.target) {
        step.attachTo = {
          element: stepData.target,
          on: stepData.position || "bottom",
        };
      }

      if (stepData.type === "navigation") {
        if (index > 0) {
          const backText =
            (window.boardingSettings &&
              window.boardingSettings.backButtonText) ||
            "Back";
          step.buttons.push({
            text: backText,
            classes: "shepherd-button-secondary",
            action: function () {
              return this.back();
            },
          });
        }

        step.buttons.push({
          text: navigationButtonText,
          classes: "shepherd-button-primary",
          action: function () {
            const isLastStep = index === tourData.steps.length - 1;

            if (isLastStep) {
              this.complete();
            } else {
              localStorage.setItem(
                "boarding-current-tour",
                JSON.stringify({
                  tourId: tourData.tourId || tourData.id,
                  nextStep: index + 1,
                })
              );
            }

            // Navigate to the specified URL
            window.location.href = stepData.navigationUrl;
          },
        });
      } else {
        if (stepData.buttons && stepData.buttons.length) {
          stepData.buttons.forEach((buttonData) => {
            const button = {
              text: buttonData.text,
              classes: buttonData.classes || "",
            };

            switch (buttonData.action) {
              case "next":
                button.action = function () {
                  return this.next();
                };
                break;
              case "back":
                button.action = function () {
                  return this.back();
                };
                break;
              case "complete":
                button.action = function () {
                  this.complete();
                };
                break;
              default:
                button.action = function () {
                  return this.next();
                };
            }

            step.buttons.push(button);
          });
        } else {
          if (index > 0) {
            const backText =
              (window.boardingSettings &&
                window.boardingSettings.backButtonText) ||
              "Back";
            step.buttons.push({
              text: backText,
              classes: "shepherd-button-secondary",
              action: function () {
                return this.back();
              },
            });
          }

          if (index === tourData.steps.length - 1) {
            const doneText =
              (window.boardingSettings &&
                window.boardingSettings.doneButtonText) ||
              "Done";
            step.buttons.push({
              text: doneText,
              classes: "shepherd-button-primary",
              action: function () {
                this.complete();
              },
            });
          } else {
            const nextText =
              (window.boardingSettings &&
                window.boardingSettings.nextButtonText) ||
              "Next";
            step.buttons.push({
              text: nextText,
              classes: "shepherd-button-primary",
              action: function () {
                return this.next();
              },
            });
          }
        }
      }

      tour.addStep(step);
    });

    try {
      if (startAtStep !== null) {
        tour.start();
        tour.show(startAtStep);
      } else {
        tour.start();
      }
      return tour;
    } catch (error) {
      return null;
    }
  };
})();
