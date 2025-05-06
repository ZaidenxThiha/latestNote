const AutoSave = {
  queue: [],
  retryInterval: 1000, // Start with 1s
  maxRetryInterval: 60000, // Max 1min
  storageKey: "noteapp_pending_saves",
  lastSavedData: null,
  isProcessing: false,
  pendingImage: null, // Store pending image for upload

  init({
    titleInput,
    contentInput,
    labelInput,
    imageInput,
    onSave,
    onIdUpdate,
    onImageUpload,
  }) {
    this.onSave = onSave;
    this.onIdUpdate = onIdUpdate;
    this.onImageUpload = onImageUpload;

    // Load any pending saves from LocalStorage
    this.loadQueue();
    console.log("Autosave initialized, queue:", this.queue);

    // Save handler
    this.flush = async () => {
      const data = {
        title: titleInput.value.trim(),
        content: contentInput.value.trim(),
        labels: labelInput.value.trim(),
        updated_at: new Date().toISOString(),
      };
      // Check for pending image
      const hasImage = imageInput.files.length > 0;
      // Only queue if data has changed or there's an image to upload
      if (
        (data.content || hasImage) &&
        (JSON.stringify(data) !== JSON.stringify(this.lastSavedData) ||
          hasImage)
      ) {
        this.queueSave(
          currentNoteId,
          data,
          hasImage ? imageInput.files[0] : null
        );
        this.lastSavedData = data;
        if (hasImage) {
          this.pendingImage = imageInput.files[0];
          imageInput.value = ""; // Clear the input after queuing
        }
      } else {
        console.log("No changes to save or empty content:", data);
      }
    };

    // Start retrying pending saves
    this.processQueue();
  },

  queueSave(noteId, data, imageFile) {
    // Remove existing entries for the same noteId to prevent duplicates
    this.queue = this.queue.filter((item) => item.noteId !== noteId);
    this.queue.push({
      noteId,
      data,
      imageFile,
      retries: 0,
      nextRetry: Date.now(),
    });
    this.saveQueue();
    console.log("Queued save:", { noteId, data, hasImage: !!imageFile });
    this.processQueue();
  },

  async processQueue() {
    if (this.isProcessing || !navigator.onLine) {
      console.log(
        `Queue processing skipped: ${
          this.isProcessing ? "already processing" : "offline"
        }, retrying in ${this.retryInterval}ms`
      );
      setTimeout(() => this.processQueue(), this.retryInterval);
      return;
    }

    this.isProcessing = true;
    const now = Date.now();
    const pending = this.queue.filter((item) => item.nextRetry <= now);

    for (const item of pending) {
      try {
        console.log("Processing queued save:", item);
        if (typeof API === "undefined") {
          throw new Error(
            "API is not defined. Please check if api.js is loaded."
          );
        }

        // Check if note is password-protected (for existing notes)
        if (item.noteId) {
          try {
            const accessResponse = await API.loadNote(item.noteId);
            if (accessResponse.is_locked && !accessResponse.success) {
              throw new Error("Password required to save this note");
            }
          } catch (error) {
            if (
              error.message.includes("403") &&
              error.cause &&
              error.cause.is_locked
            ) {
              // Prompt for password and retry save
              return new Promise((resolve, reject) => {
                promptPassword(item.noteId, "save", () => {
                  this.onSave(item.noteId, item.data)
                    .then((response) => {
                      if (response.success) {
                        if (!item.noteId && response.id) {
                          this.onIdUpdate(response.id);
                        }
                        if (item.imageFile) {
                          const formData = new FormData();
                          formData.append("image", item.imageFile);
                          formData.append("note_id", response.id);
                          this.onImageUpload(formData).then(() => {
                            console.log(
                              "Image uploaded for note ID:",
                              response.id
                            );
                          });
                        }
                        this.queue = this.queue.filter((q) => q !== item);
                        this.saveQueue();
                        console.log(
                          "Save successful, removed from queue:",
                          response
                        );
                        loadNotes();
                        resolve(response);
                      } else {
                        throw new Error(
                          response.error || "Unknown server error"
                        );
                      }
                    })
                    .catch((saveError) => {
                      console.error(
                        "Save failed after password prompt:",
                        saveError.message,
                        item
                      );
                      reject(saveError);
                    });
                });
              });
            }
            throw error;
          }
        }

        // Save the note
        const response = await this.onSave(item.noteId, item.data);
        console.log("Server response:", response);
        if (response.success) {
          if (!item.noteId && response.id) {
            this.onIdUpdate(response.id); // Update currentNoteId for new notes
          }
          // If there's an image, upload it
          if (item.imageFile) {
            const formData = new FormData();
            formData.append("image", item.imageFile);
            formData.append("note_id", response.id);
            await this.onImageUpload(formData);
            console.log("Image uploaded for note ID:", response.id);
          }
          this.queue = this.queue.filter((q) => q !== item);
          this.saveQueue();
          console.log("Save successful, removed from queue:", response);
          loadNotes();
        } else {
          throw new Error(response.error || "Unknown server error");
        }
      } catch (error) {
        if (
          error.message.includes("403") ||
          error.message.includes("API is not defined") ||
          error.message.includes("Unexpected token") ||
          error.message.includes("Password required")
        ) {
          // Stop retrying on permission errors, script loading issues, JSON parsing errors, or password issues
          console.error(
            "Unrecoverable error, stopping retries:",
            error.message,
            item
          );
          this.queue = this.queue.filter((q) => q !== item);
          this.saveQueue();
          UI.showError(
            error.message.includes("403")
              ? "You do not have permission to save this note"
              : error.message.includes("API is not defined")
              ? "Application error: API is not loaded. Please refresh the page."
              : error.message.includes("Password required")
              ? "Password required to save this note. Please unlock it first."
              : "Server error: Failed to process request. Please try again."
          );
        } else {
          item.retries++;
          item.nextRetry =
            now +
            Math.min(
              this.retryInterval * Math.pow(2, item.retries),
              this.maxRetryInterval
            );
          this.saveQueue();
          console.error("Save failed, retrying:", error.message, item);
        }
      }
    }

    this.isProcessing = false;
    if (this.queue.length) {
      setTimeout(() => this.processQueue(), this.retryInterval);
    }
  },

  saveQueue() {
    localStorage.setItem(
      this.storageKey,
      JSON.stringify(
        this.queue.map((item) => ({
          ...item,
          imageFile: null, // Don't store file objects in LocalStorage
        }))
      )
    );
  },

  loadQueue() {
    const saved = localStorage.getItem(this.storageKey);
    if (saved) {
      this.queue = JSON.parse(saved);
      console.log("Loaded queue from LocalStorage:", this.queue);
    }
  },

  hasPendingSaves() {
    return this.queue.length > 0;
  },
};
