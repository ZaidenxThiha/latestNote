const UI = {
  showSaving() {
    const indicator = document.getElementById("save-indicator");
    indicator.textContent = "Saving...";
    indicator.className = "saving animate-pulse";
    console.log("UI: Showing saving indicator");
  },

  showSaved() {
    const indicator = document.getElementById("save-indicator");
    indicator.textContent = "Saved ✓";
    indicator.className = "saved animate-fade-in";
    console.log("UI: Showing saved indicator");
    setTimeout(() => {
      indicator.className = "saved animate-fade-out";
      setTimeout(() => {
        indicator.textContent = "";
        indicator.className = "";
        console.log("UI: Cleared saved indicator");
      }, 500);
    }, 3000);
  },

  showError(message) {
    let displayMessage = message;
    if (message.includes("403")) {
      displayMessage = "You do not have permission to perform this action";
    } else if (message.includes("Label already exists")) {
      displayMessage = "This label name is already in use";
    } else if (message.includes("Label not found")) {
      displayMessage = "The specified label does not exist";
    } else if (message.includes("Label name required")) {
      displayMessage = "Please enter a label name";
    } else if (
      message.includes("Please save the note before uploading an image")
    ) {
      displayMessage =
        "Please save the note before uploading an image. Click outside the form to save.";
    } else if (message.includes("API is not defined")) {
      displayMessage =
        "Application error: API is not loaded. Please refresh the page.";
    } else if (message.includes("Unexpected token")) {
      displayMessage =
        "Failed to load note: Server returned an unexpected response. Please try again.";
    } else if (message.includes("Password required")) {
      displayMessage =
        "This note is locked. Please enter the password to view or edit.";
    } else if (message.includes("Incorrect password")) {
      displayMessage = "Incorrect password for this note. Please try again.";
    } else if (message.includes("Invalid callback")) {
      displayMessage =
        "Application error: Unable to process password prompt. Please try again.";
    }

    const errorDiv = document.createElement("p");
    errorDiv.className = "error animate-fade-in";
    errorDiv.textContent = displayMessage;
    document.querySelector("body").prepend(errorDiv);
    console.error("UI: Error displayed:", message);
    setTimeout(() => {
      errorDiv.className = "error animate-fade-out";
      setTimeout(() => errorDiv.remove(), 500);
    }, 3000);
  },

  showPasswordPrompt(noteId, action, callback) {
    const modal = document.getElementById("password-modal");
    if (!modal) {
      console.error("Password modal not found in DOM");
      UI.showError("Application error: Password prompt unavailable");
      return;
    }

    const title = document.getElementById("password-modal-title");
    const input = document.getElementById("note-password");
    const submitButton = document.getElementById("password-submit");

    if (!title || !input || !submitButton) {
      console.error("Password modal elements missing");
      UI.showError("Application error: Password prompt unavailable");
      return;
    }

    title.textContent =
      action === "set"
        ? "Set Note Password"
        : action === "change"
        ? "Change Note Password"
        : action === "save"
        ? "Unlock Note to Save"
        : "Unlock Note to View";

    input.value = "";
    modal.style.display = "block";

    const handleSubmit = () => {
      const password = input.value.trim();
      if (!password) {
        UI.showError("Password required");
        return;
      }
      if (typeof callback !== "function") {
        console.error(
          "Callback is not a function in showPasswordPrompt, received:",
          callback
        );
        UI.showError("Invalid callback for password prompt");
        closeModal();
        return;
      }
      try {
        callback(password);
      } catch (error) {
        console.error("Error executing callback in showPasswordPrompt:", error);
        UI.showError("Application error: Failed to process password");
      }
      closeModal();
    };

    input.onkeydown = (event) => {
      if (event.key === "Enter") {
        event.preventDefault();
        handleSubmit();
      }
    };

    submitButton.onclick = handleSubmit;

    function closeModal() {
      modal.style.display = "none";
      input.onkeydown = null;
      submitButton.onclick = null;
    }

    const cancelButton = document.querySelector(
      "#password-modal .modal-content button:last-child"
    );
    if (cancelButton) {
      cancelButton.onclick = closeModal;
    } else {
      console.warn("Cancel button not found in password modal");
    }
  },

  initViewToggle() {
    const viewToggle = document.getElementById("view-toggle");
    const notesContainer = document.querySelector(".notes-container");
    const savedView = localStorage.getItem("noteView") || "grid";

    notesContainer.classList.add(savedView);
    viewToggle.textContent = savedView === "grid" ? "Grid View" : "List View";

    viewToggle.addEventListener("click", () => {
      const currentView = localStorage.getItem("noteView") || "grid";
      const newView = currentView === "grid" ? "list" : "grid";
      notesContainer.classList.remove("grid", "list");
      notesContainer.classList.add(newView);
      viewToggle.textContent = newView === "grid" ? "Grid View" : "List View";
      localStorage.setItem("noteView", newView);
      console.log(`UI: Switched to ${newView} view`);
    });
  },
};
