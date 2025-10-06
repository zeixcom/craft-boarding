/**
 * Boarding Utils Module
 * Contains utility functions used throughout the application
 */
(function () {
  // Ensure Boarding namespace exists
  window.Boarding = window.Boarding || {};

  // Create utils namespace
  window.Boarding.utils = {
    /**
     * Log a message to the console with appropriate level
     *
     * @param {string} message - The message to log
     * @param {string} level - Log level (info, warn, error)
     */
    log: function (message, level = "info") {
      if (level === "error") {
        console.error("Boarding:", message);
      } else if (level === "warn") {
        console.warn("Boarding:", message);
      } else {
        console.log("Boarding:", message);
      }
    },


    /**
     * Normalize a tour ID for consistent comparison
     * Handles various ID formats including UUIDs with/without dashes
     *
     * @param {string|number} id - The ID to normalize
     * @return {string} Normalized ID string
     */
    normalizeId: function (id) {
      if (id === null || id === undefined) {
        return "";
      }

      // Convert to string and trim
      const idStr = String(id).trim();

      // For UUIDs, ensure consistent format (lowercase, no dashes)
      const uuidPattern =
        /^[0-9a-f]{8}-?[0-9a-f]{4}-?[0-9a-f]{4}-?[0-9a-f]{4}-?[0-9a-f]{12}$/i;
      if (uuidPattern.test(idStr)) {
        return idStr.toLowerCase().replace(/-/g, "");
      }

      return idStr;
    },

    /**
     * Find a tour by ID with robust comparison
     *
     * @param {Array} tours - Array of tour objects
     * @param {string} tourId - Tour ID to find
     * @return {Object|null} Found tour or null
     */
    findTourById: function (tours, tourId) {
      if (!tours || !Array.isArray(tours) || tours.length === 0 || !tourId) {
        this.log("Invalid tours array or tourId in findTourById", "error");
        return null;
      }

      const normalizedSearchId = this.normalizeId(tourId);

      return tours.find((t) => {
        const tourIdNormalized = this.normalizeId(t.tourId);
        const idNormalized = this.normalizeId(t.id);

        return (
          tourIdNormalized === normalizedSearchId ||
          idNormalized === normalizedSearchId
        );
      });
    },
  };
})();
