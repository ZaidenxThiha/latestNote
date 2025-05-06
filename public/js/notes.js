let selectedLabel = "";

function resetForm() {
  document.getElementById("note-form").reset();
  document.getElementById("labels").value = "";
  document.getElementById("image-upload").value = "";
  currentNoteId = null;
  AutoSave.pendingImage = null;
  window.history.replaceState({}, "", "notes_frontend.php");
  loadNotes();
}

function updateNoteUI(noteId, noteData, isAccessible, isLocked) {
  const noteElement = document.querySelector(`.note[data-id="${noteId}"]`);
  if (!noteElement) {
    console.warn(`Note element with ID ${noteId} not found`);
    loadNotes(); // Fallback to full reload if note not found
    return;
  }

  let html = '<div class="note-icons">';
  if (noteData.is_pinned) {
    html += '<span class="pin-indicator" title="Pinned">📍</span>';
  }
  if (isLocked) {
    html += '<span class="lock-indicator" title="Password-Protected">🔒</span>';
  }
  if (noteData.shared_emails && noteData.shared_emails.length) {
    html += '<span class="share-indicator" title="Shared">📤</span>';
  }
  html += '</div>';

  if (isAccessible) {
    html += `<h3>${htmlspecialchars(noteData.title || '')}</h3>`;
    html += `<p>${nl2br(htmlspecialchars(noteData.content || ''))}</p>`;
    if (noteData.images && noteData.images.length) {
      html += '<div class="note-images">';
      noteData.images.forEach((image) => {
        html += `<img src="${htmlspecialchars(image)}" alt="Note image">`;
      });
      html += '</div>';
    }
    if (noteData.labels) {
      html += '<div class="note-labels">';
      noteData.labels.split(",").forEach((label) => {
        html += `<span class="label">${htmlspecialchars(label)}</span>`;
      });
      html += '</div>';
    }
    if (noteData.shared_emails && noteData.shared_emails.length) {
      html += '<div class="note-shared">';
      html += `<span>Shared with: ${htmlspecialchars(noteData.shared_emails.join(", "))}</span>`;
      html += '</div>';
    }
  } else {
    html += `<h3>${htmlspecialchars(noteData.title || '')}</h3>`;
    html += '<p>Enter password to view content</p>';
  }
  html += `<small>Last updated: ${noteData.updated_at}</small><br>`;
  html += '<div class="note-actions">';
  const canEdit =
    noteData.is_owner ||
    (noteData.shared_permissions &&
      noteData.shared_permissions.includes("edit") &&
      noteData.shared_user_ids &&
      noteData.shared_user_ids.includes(userId));
  if (isAccessible && canEdit) {
    html += `<a href="#" onclick="editNote(${noteId}); return false;">✏️ Edit</a> | `;
  }
  if (noteData.is_owner) {
    if (isAccessible) {
      html += `<a href="#" onclick="deleteNote(${noteId})">🗑️ Delete</a> | `;
      html += `<a href="#" onclick="openShareModal(${noteId})">📤 Share</a> | `;
      if (isLocked) {
        html += `<a href="#" onclick="relockNote(${noteId})">🔐 Relock</a> | `;
        html += `<div class="dropdown">`;
        html += `<a href="#" class="settings-button" onclick="toggleDropdown(event, 'dropdown-${noteId}')">⚙️ Settings</a>`;
        html += `<div class="dropdown-content" id="dropdown-${noteId}">`;
        html += `<a href="#" onclick="openPasswordModal(${noteId}, 'change')">🔐 Change Password</a>`;
        html += `<a href="#" onclick="removePassword(${noteId})">🔓 Remove Password</a>`;
        html += `</div>`;
        html += `</div> | `;
      } else {
        html += `<a href="#" onclick="openPasswordModal(${noteId}, 'set')">🔒 Lock Note</a> | `;
      }
    } else {
      html += `<a href="#" onclick="promptPassword(${noteId}, 'access')">🔓 Unlock</a> | `;
    }
  }
  if (isAccessible) {
    html += noteData.is_pinned
      ? `<a href="#" onclick="pinNote(${noteId}, 0)">📌 Unpin</a>`
      : `<a href="#" onclick="pinNote(${noteId}, 1)">📍 Pin</a>`;
  }
  html += '</div>';

  noteElement.innerHTML = html;
}

function htmlspecialchars(str) {
  return str
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&apos;");
}

function nl2br(str) {
  return str.replace(/(?:\r\n|\r|\n)/g, "<br>");
}

function toggleDropdown(event, dropdownId) {
  event.preventDefault();
  const dropdown = document.getElementById(dropdownId);
  const isVisible = dropdown.style.display === "block";
  document
    .querySelectorAll(".dropdown-content")
    .forEach((dd) => (dd.style.display = "none"));
  dropdown.style.display = isVisible ? "none" : "block";
}

function editNote(id) {
  API.loadNote(id)
    .then((note) => {
      if (note && note.success) {
        document.getElementById("title").value = note.title || "";
        document.getElementById("content").value = note.content || "";
        document.getElementById("labels").value = note.labels || "";
        currentNoteId = id;
        if (note.is_owner) {
          document.getElementById("share-button").style.display = "inline-block";
          updateShareSettings(
            note.shared_emails,
            note.shared_permissions,
            note.shared_user_ids
          );
        } else {
          document.getElementById("share-button").style.display = "none";
        }
        window.history.replaceState({}, "", `notes_frontend.php?action=edit&id=${id}`);
      } else {
        UI.showError(note.error || "Failed to load note");
      }
    })
    .catch((error) => {
      if (error.message.includes("403") && error.cause && error.cause.is_locked) {
        promptPassword(id, "edit", () => {
          API.loadNote(id)
            .then((note) => {
              if (note && note.success) {
                document.getElementById("title").value = note.title || "";
                document.getElementById("content").value = note.content || "";
                document.getElementById("labels").value = note.labels || "";
                currentNoteId = id;
                if (note.is_owner) {
                  document.getElementById("share-button").style.display = "inline-block";
                  updateShareSettings(
                    note.shared_emails,
                    note.shared_permissions,
                    note.shared_user_ids
                  );
                } else {
                  document.getElementById("share-button").style.display = "none";
                }
                window.history.replaceState({}, "", `notes_frontend.php?action=edit&id=${id}`);
              } else {
                UI.showError(note.error || "Failed to load note after unlocking");
              }
            })
            .catch((retryError) => {
              UI.showError("Network error during note loading: " + retryError.message);
            });
        });
      } else {
        UI.showError("Network error during note loading: " + error.message);
      }
    });
}

function openShareModal(noteId) {
  promptPassword(noteId, "share", () => {
    currentNoteId = noteId;
    document.getElementById("share-modal").style.display = "block";
    API.loadNote(noteId).then((note) => {
      if (note && note.success) {
        updateShareSettings(
          note.shared_emails,
          note.shared_permissions,
          note.shared_user_ids
        );
      }
    });
  });
}

function closeShareModal() {
  document.getElementById("share-modal").style.display = "none";
  document.getElementById("share-emails").value = "";
  document.getElementById("share-permission").value = "read";
}

function updateShareSettings(emails, permissions, userIds) {
  const shareSettings = document.getElementById("share-settings");
  shareSettings.innerHTML = "";
  if (emails && emails.length) {
    emails.forEach((email, index) => {
      const userId = userIds[index];
      const permission = permissions[index];
      const div = document.createElement("div");
      div.className = "share-setting";
      div.innerHTML = `
                <span>${email} (${permission})</span>
                <select onchange="updatePermission(${userId}, this.value)">
                    <option value="read" ${permission === "read" ? "selected" : ""}>Read</option>
                    <option value="edit" ${permission === "edit" ? "selected" : ""}>Edit</option>
                </select>
                <button onclick="revokeShare(${userId})">Revoke</button>
            `;
      shareSettings.appendChild(div);
    });
  }
}

function shareNote() {
  const emails = document.getElementById("share-emails").value.trim();
  const permission = document.getElementById("share-permission").value;
  if (!currentNoteId || !emails) {
    UI.showError("Note ID or emails required");
    return;
  }
  API.shareNote(currentNoteId, emails, permission)
    .then((data) => {
      if (data.success) {
        UI.showSaved();
        closeShareModal();
        loadNotes();
      } else {
        UI.showError(data.error || "Failed to share note");
      }
    })
    .catch((error) => {
      UI.showError("Network error during sharing: " + error.message);
    });
}

function updatePermission(userId, permission) {
  if (!currentNoteId) {
    UI.showError("No note selected");
    return;
  }
  API.updateShare(currentNoteId, userId, permission)
    .then((data) => {
      if (data.success) {
        UI.showSaved();
        loadNotes();
      } else {
        UI.showError(data.error || "Failed to update permission");
      }
    })
    .catch((error) => {
      UI.showError("Network error during permission update: " + error.message);
    });
}

function revokeShare(userId) {
  if (!currentNoteId) {
    UI.showError("No note selected");
    return;
  }
  if (confirm("Revoke sharing for this user?")) {
    API.revokeShare(currentNoteId, userId)
      .then((data) => {
        if (data.success) {
          UI.showSaved();
          loadNotes();
          API.loadNote(currentNoteId).then((note) => {
            if (note && note.success) {
              updateShareSettings(
                note.shared_emails,
                note.shared_permissions,
                note.shared_user_ids
              );
            }
          });
        } else {
          UI.showError(data.error || "Failed to revoke sharing");
        }
      })
      .catch((error) => {
        UI.showError("Network error during revoke: " + error.message);
      });
  }
}

function uploadImage(formData) {
  API.uploadImage(formData)
    .then((data) => {
      if (data.success) {
        loadNotes();
      } else {
        UI.showError(data.error || "Failed to upload image");
      }
    })
    .catch((error) => {
      UI.showError("Network error during image upload: " + error.message);
    });
}

function loadNotes(search = "", label = selectedLabel) {
  console.log(`Loading notes with search: '${search}', label: '${label}'`);
  API.loadNotes(search, label)
    .then((html) => {
      document.querySelector(".notes-container").innerHTML = html;
      console.log("Notes loaded successfully via AJAX");
    })
    .catch((error) => {
      UI.showError("Failed to load notes: " + error.message);
      console.error("Load notes error:", error);
    });
}

function deleteNote(id) {
  promptPassword(id, "delete", () => {
    if (confirm("Delete this note?")) {
      API.deleteNote(id)
        .then((data) => {
          if (data.success) {
            loadNotes();
          } else {
            UI.showError(data.error || "Failed to delete note");
          }
        })
        .catch((error) => {
          UI.showError("Network error during deletion: " + error.message);
        });
    }
  });
}

function pinNote(id, pin) {
  promptPassword(id, "pin", () => {
    API.pinNote(id, pin)
      .then((data) => {
        if (data.success) {
          loadNotes();
        } else {
          UI.showError(data.error || "Failed to update pin status");
        }
      })
      .catch((error) => {
        UI.showError("Network error during pin action: " + error.message);
      });
  });
}

function searchNotes() {
  const query = document.getElementById("search").value.trim();
  loadNotes(query);
}

function selectLabel(label) {
  selectedLabel = label === selectedLabel ? "" : label;
  console.log(`Selected label: '${selectedLabel}'`);
  document.querySelectorAll(".label-item").forEach((item) => {
    item.classList.toggle("selected", item.textContent === selectedLabel);
  });
  loadNotes();
}

function addLabel() {
  const labelName = document.getElementById("new-label-input").value.trim();
  if (!labelName) {
    UI.showError("Label name required");
    return;
  }
  API.addLabel(labelName)
    .then((data) => {
      if (data.success) {
        document.getElementById("new-label-input").value = "";
        loadLabels();
      } else {
        UI.showError(data.error || "Failed to add label");
      }
    })
    .catch((error) => {
      UI.showError("Network error during label addition: " + error.message);
    });
}

function openRenameLabelModal(oldName) {
  document.getElementById("rename-label-old").value = oldName;
  document.getElementById("rename-label-new").value = oldName;
  document.getElementById("rename-label-modal").style.display = "block";
}

function closeRenameLabelModal() {
  document.getElementById("rename-label-modal").style.display = "none";
  document.getElementById("rename-label-old").value = "";
  document.getElementById("rename-label-new").value = "";
}

function renameLabel() {
  const oldName = document.getElementById("rename-label-old").value.trim();
  const newName = document.getElementById("rename-label-new").value.trim();
  if (!oldName || !newName) {
    UI.showError("Old and new label names required");
    return;
  }
  API.renameLabel(oldName, newName)
    .then((data) => {
      if (data.success) {
        closeRenameLabelModal();
        loadLabels();
        loadNotes();
      } else {
        UI.showError(data.error || "Failed to rename label");
      }
    })
    .catch((error) => {
      UI.showError("Network error during label renaming: " + error.message);
    });
}

function deleteLabel(labelName) {
  if (confirm(`Delete label "${labelName}"?`)) {
    API.deleteLabel(labelName)
      .then((data) => {
        if (data.success) {
          loadLabels();
          if (selectedLabel === labelName) {
            selectedLabel = "";
            loadNotes();
          }
        } else {
          UI.showError(data.error || "Failed to delete label");
        }
      })
      .catch((error) => {
        UI.showError("Network error during label deletion: " + error.message);
      });
  }
}

function openPasswordModal(noteId, action) {
  UI.showPasswordPrompt(noteId, action, (password) => {
    API.setPassword(noteId, password)
      .then((data) => {
        if (data.success) {
          UI.showSaved();
          API.loadNote(noteId).then((note) => {
            if (note && note.success) {
              updateNoteUI(
                noteId,
                {
                  title: note.title,
                  content: note.content,
                  labels: note.labels,
                  updated_at: note.updated_at,
                  is_pinned: note.is_pinned || false,
                  is_owner: note.is_owner,
                  shared_emails: note.shared_emails || [],
                  shared_permissions: note.shared_permissions || [],
                  shared_user_ids: note.shared_user_ids || [],
                  images: note.images || [],
                },
                true,
                note.is_locked
              );
            } else {
              loadNotes();
            }
          });
        } else {
          UI.showError(data.error || "Failed to set password");
        }
      })
      .catch((error) => {
        UI.showError("Network error during password setting: " + error.message);
      });
  });
}

function removePassword(noteId) {
  if (confirm("Remove password protection for this note?")) {
    API.removePassword(noteId)
      .then((data) => {
        if (data.success) {
          UI.showSaved();
          API.loadNote(noteId).then((note) => {
            if (note && note.success) {
              updateNoteUI(
                noteId,
                {
                  title: note.title,
                  content: note.content,
                  labels: note.labels,
                  updated_at: note.updated_at,
                  is_pinned: note.is_pinned || false,
                  is_owner: note.is_owner,
                  shared_emails: note.shared_emails || [],
                  shared_permissions: note.shared_permissions || [],
                  shared_user_ids: note.shared_user_ids || [],
                  images: note.images || [],
                },
                true,
                false
              );
            } else {
              loadNotes();
            }
          });
        } else {
          UI.showError(data.error || "Failed to remove password");
        }
      })
      .catch((error) => {
        UI.showError("Network error during password removal: " + error.message);
      });
  }
}

function relockNote(noteId) {
  if (
    confirm(
      "Relock this note? You will need to enter the password again to view it."
    )
  ) {
    // Get the current title from the form (if editing) or use a fallback
    const titleInput = document.getElementById("title");
    const currentTitle = titleInput && titleInput.value ? titleInput.value : '';
    fetch("notes_backend.php", {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      body: `relock=1&note_id=${encodeURIComponent(noteId)}`,
    })
      .then((response) => response.json())
      .then((data) => {
        if (data.success) {
          UI.showSaved();
          updateNoteUI(
            noteId,
            {
              title: currentTitle,
              content: "",
              labels: "",
              updated_at: new Date().toISOString(),
              is_pinned: false,
              is_owner: true,
              shared_emails: [],
              shared_permissions: [],
              shared_user_ids: [],
              images: [],
            },
            false,
            true
          );
        } else {
          UI.showError(data.error || "Failed to relock note");
        }
      })
      .catch((error) => {
        UI.showError("Network error during relock: " + error.message);
      });
  }
}

function promptPassword(noteId, action, callback = () => {}) {
  if (typeof callback !== "function") {
    console.warn(
      "Invalid callback provided to promptPassword, using default no-op"
    );
    callback = () => {};
  }
  UI.showPasswordPrompt(noteId, action, (password) => {
    API.verifyPassword(noteId, password)
      .then((data) => {
        if (data.success) {
          API.loadNote(noteId).then((note) => {
            if (note && note.success) {
              updateNoteUI(
                noteId,
                {
                  title: note.title,
                  content: note.content,
                  labels: note.labels,
                  updated_at: note.updated_at,
                  is_pinned: note.is_pinned || false,
                  is_owner: note.is_owner,
                  shared_emails: note.shared_emails || [],
                  shared_permissions: note.shared_permissions || [],
                  shared_user_ids: note.shared_user_ids || [],
                  images: note.images || [],
                },
                true,
                note.is_locked
              );
              callback(noteId);
            } else {
              UI.showError(note.error || "Failed to load note after unlocking");
              loadNotes();
            }
          });
        } else {
          UI.showError(data.error || "Failed to verify password");
        }
      })
      .catch((error) => {
        UI.showError(
          "Network error during password verification: " + error.message
        );
      });
  });
}

function loadLabels() {
  fetch("notes_backend.php?action=labels")
    .then((response) => response.json())
    .then((data) => {
      if (data.success) {
        const labelList = document.getElementById("label-list");
        labelList.innerHTML =
          '<li class="label-item" onclick="selectLabel(\'\')">All Notes</li>';
        data.labels.forEach((label) => {
          const li = document.createElement("li");
          li.className = "label-item";
          li.innerHTML = `
                        <span onclick="selectLabel('${label}')">${label}</span>
                        <span class="label-actions">
                            <button onclick="openRenameLabelModal('${label}')">✏️</button>
                            <button onclick="deleteLabel('${label}')">🗑️</button>
                        </span>
                    `;
          labelList.appendChild(li);
        });
      } else {
        UI.showError(data.error || "Failed to load labels");
      }
    })
    .catch((error) => {
      UI.showError("Network error during label loading: " + error.message);
    });
}

document.addEventListener("DOMContentLoaded", () => {
  const title = document.getElementById("title");
  const content = document.getElementById("content");
  const labels = document.getElementById("labels");
  const search = document.getElementById("search");
  const noteFormContainer = document.getElementById("note-form-container");
  const imageUpload = document.getElementById("image-upload");
  const newLabelInput = document.getElementById("new-label-input");

  if (
    title &&
    content &&
    labels &&
    search &&
    noteFormContainer &&
    imageUpload &&
    newLabelInput
  ) {
    console.log("Form elements loaded, including image upload and label input");
    search.addEventListener("input", searchNotes);

    document.addEventListener("click", (event) => {
      if (!noteFormContainer.contains(event.target)) {
        AutoSave.flush();
      }
    });

    newLabelInput.addEventListener("keydown", (event) => {
      if (event.key === "Enter") {
        event.preventDefault();
        addLabel();
      }
    });

    AutoSave.init({
      titleInput: title,
      contentInput: content,
      labelInput: labels,
      imageInput: imageUpload,
      onSave: (noteId, data) => {
        UI.showSaving();
        if (noteId) {
          return API.loadNote(noteId).then((accessResponse) => {
            if (accessResponse.is_locked && !accessResponse.success) {
              return new Promise((resolve, reject) => {
                promptPassword(noteId, "save", (id) => {
                  API.saveNote(noteId, data)
                    .then((response) => {
                      UI.showSaved();
                      loadLabels();
                      resolve(response);
                    })
                    .catch((error) => {
                      UI.showError(
                        error.message.includes("403")
                          ? "You do not have permission to save this note"
                          : "Failed to save note: " + error.message
                      );
                      reject(error);
                    });
                });
              });
            }
            return API.saveNote(noteId, data)
              .then((response) => {
                UI.showSaved();
                loadLabels();
                return response;
              })
              .catch((error) => {
                UI.showError(
                  error.message.includes("403")
                    ? "You do not have permission to save this note"
                    : "Failed to save note: " + error.message
                );
                throw error;
              });
          });
        }
        return API.saveNote(noteId, data)
          .then((response) => {
            UI.showSaved();
            loadLabels();
            return response;
          })
          .catch((error) => {
            if (error.message.includes("403")) {
              UI.showError("You do not have permission to save this note");
              resetForm();
            } else {
              UI.showError("Failed to save note: " + error.message);
            }
            throw error;
          });
      },
      onIdUpdate: (newId) => {
        currentNoteId = newId;
        window.history.replaceState({}, "", `notes_frontend.php?id=${newId}`);
      },
      onImageUpload: (formData) => {
        return uploadImage(formData);
      },
    });

    if (currentNoteId) {
      editNote(currentNoteId);
    }

    window.addEventListener("beforeunload", (event) => {
      if (AutoSave.hasPendingSaves()) {
        AutoSave.flush();
        event.returnValue =
          "You have unsaved changes. Are you sure you want to leave?";
      }
    });

    UI.initViewToggle();
    loadNotes();
    loadLabels();
  } else {
    UI.showError("Form initialization error: Missing form elements");
    console.error("Missing elements:", {
      title,
      content,
      labels,
      search,
      noteFormContainer,
      imageUpload,
      newLabelInput,
    });
  }
});

document.addEventListener("click", (event) => {
  if (!event.target.closest(".dropdown")) {
    document
      .querySelectorAll(".dropdown-content")
      .forEach((dd) => (dd.style.display = "none"));
  }
});