"use strict";

import {
  formatDate,
  pageConfig,
  requestJSON,
  toLocalISODate
} from "./common.js";

const config = pageConfig();
const IS_ADMIN = config.isAdmin;
const API_URL = config.apiUrl;

const form = document.querySelector("#weight-form");
const dateInput = document.querySelector("#entry-date");
const weightInput = document.querySelector("#weight-kg");
const saveButton = document.querySelector("#save-button");
const feedback = document.querySelector("#feedback");
const latestWeight = document.querySelector("#latest-weight");
const latestWeightValue = document.querySelector("#latest-weight-value");
const latestWeightDate = document.querySelector("#latest-weight-date");
const emptyLatest = document.querySelector("#empty-latest");
const historyList = document.querySelector("#history-list");
const historyAverage = document.querySelector("#history-average");
const historyAverageValue = document.querySelector("#history-average-value");

const state = { records: [] };
const weightFormatter = new Intl.NumberFormat("ru-RU", {
  minimumFractionDigits: 2,
  maximumFractionDigits: 2
});

function formatWeight(value) {
  return `${weightFormatter.format(value)} кг`;
}

function numberFrom(input) {
  return Number.isFinite(input.valueAsNumber) ? input.valueAsNumber : NaN;
}

function setFeedback(message, isError = false) {
  if (!feedback) return;
  feedback.textContent = message;
  feedback.classList.toggle("error", isError);
}

function renderLoading() {
  if (!IS_ADMIN) {
    latestWeight.hidden = true;
    emptyLatest.hidden = true;
  }
  historyAverage.hidden = true;
  historyList.innerHTML = '<p class="empty-history">Загружаем историю…</p>';
}

function renderError() {
  if (!IS_ADMIN) {
    latestWeight.hidden = true;
    emptyLatest.hidden = true;
  }
  historyAverage.hidden = true;
  historyList.innerHTML = `
    <div class="empty-history">
      <p>Не удалось загрузить историю.</p>
      <button class="text-button" type="button" data-action="retry">Повторить</button>
    </div>`;
}

function renderLatest(records) {
  if (IS_ADMIN) return;

  if (!records.length) {
    latestWeight.hidden = true;
    emptyLatest.hidden = false;
    return;
  }

  const latest = records[0];
  latestWeightValue.textContent = formatWeight(latest.weightKg);
  latestWeightDate.textContent = formatDate(latest.date);
  latestWeight.hidden = false;
  emptyLatest.hidden = true;
}

function renderAverage(records) {
  if (!records.length) {
    historyAverage.hidden = true;
    return;
  }

  const latestDate = records[0].date;
  const firstDate = new Date(`${latestDate}T12:00:00`);
  firstDate.setDate(firstDate.getDate() - 6);
  const firstDateISO = toLocalISODate(firstDate);
  const recentRecords = records.filter((record) =>
    record.date >= firstDateISO && record.date <= latestDate
  );
  const total = recentRecords.reduce((sum, record) => sum + record.weightKg, 0);

  historyAverageValue.textContent = formatWeight(total / recentRecords.length);
  historyAverage.hidden = false;
}

function renderHistory() {
  const records = [...state.records].sort((a, b) => b.date.localeCompare(a.date));
  renderLatest(records);
  renderAverage(records);

  if (!records.length) {
    historyList.innerHTML = `<p class="empty-history">${IS_ADMIN
      ? "Сохраните первое взвешивание — оно появится здесь."
      : "История пока пуста."}</p>`;
    return;
  }

  historyList.innerHTML = records.map((record) => `
    <article class="weight-history-row">
      <div class="weight-history-main">
        <time datetime="${record.date}">${formatDate(record.date)}</time>
        <strong class="weight-history-value">${formatWeight(record.weightKg)}</strong>
      </div>
      ${IS_ADMIN ? `
        <div class="weight-history-actions">
          <button class="text-button" type="button" data-action="edit" data-date="${record.date}">Изменить</button>
          <button class="text-button danger" type="button" data-action="delete" data-date="${record.date}">Удалить</button>
        </div>` : ""}
    </article>`).join("");
}

async function refreshRecords({ showLoading = false } = {}) {
  if (showLoading) renderLoading();

  try {
    const payload = await requestJSON(API_URL);
    if (!Array.isArray(payload.records)) {
      throw new Error("Сервер вернул некорректную историю");
    }

    state.records = payload.records;
    renderHistory();
    return true;
  } catch (error) {
    renderError();
    return false;
  }
}

function loadDate(date) {
  if (!IS_ADMIN) return;
  const record = state.records.find((item) => item.date === date);
  dateInput.value = date;
  weightInput.value = record ? record.weightKg : "";
  saveButton.textContent = record ? "Обновить запись" : "Сохранить вес";
  setFeedback("");
}

function validateEntry() {
  if (!dateInput.value) return "Выберите дату";
  if (dateInput.value > toLocalISODate(new Date())) return "Будущую дату выбрать нельзя";
  if (weightInput.value === "" || !Number.isFinite(numberFrom(weightInput)) || numberFrom(weightInput) <= 0) {
    return "Введите положительный вес";
  }
  return "";
}

if (form) {
  dateInput.max = toLocalISODate(new Date());
  loadDate(toLocalISODate(new Date()));

  dateInput.addEventListener("change", () => loadDate(dateInput.value));

  weightInput.addEventListener("input", () => {
    if (weightInput.value !== "" && numberFrom(weightInput) <= 0) {
      weightInput.value = "";
    }
    setFeedback("");
  });

  form.addEventListener("submit", async (event) => {
    event.preventDefault();
    const validationMessage = validateEntry();

    if (validationMessage) {
      setFeedback(validationMessage, true);
      return;
    }

    const date = dateInput.value;
    const isUpdate = state.records.some((record) => record.date === date);
    saveButton.disabled = true;
    setFeedback("Сохраняем…");

    try {
      await requestJSON(`${API_URL}?date=${encodeURIComponent(date)}`, {
        method: "PUT",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ weightKg: numberFrom(weightInput) })
      });

      const refreshed = await refreshRecords();
      if (refreshed) loadDate(date);
      setFeedback(refreshed
        ? isUpdate ? "Запись обновлена" : "Вес сохранён"
        : "Вес сохранён, но историю не удалось обновить", !refreshed);
    } catch (error) {
      setFeedback("Не удалось сохранить вес. Проверьте соединение.", true);
    } finally {
      saveButton.disabled = false;
    }
  });
}

historyList.addEventListener("click", async (event) => {
  const button = event.target.closest("button[data-action]");
  if (!button) return;

  const { action, date } = button.dataset;
  if (action === "retry") {
    await refreshRecords({ showLoading: true });
    return;
  }

  if (action === "edit") {
    loadDate(date);
    document.querySelector("#page-title").scrollIntoView({ behavior: "smooth" });
    return;
  }

  if (action === "delete" && window.confirm(`Удалить взвешивание за ${formatDate(date)}?`)) {
    button.disabled = true;
    try {
      await requestJSON(`${API_URL}?date=${encodeURIComponent(date)}`, { method: "DELETE" });
      const refreshed = await refreshRecords();
      if (refreshed && dateInput.value === date) loadDate(date);
      setFeedback(refreshed
        ? "Запись удалена"
        : "Запись удалена, но историю не удалось обновить", !refreshed);
    } catch (error) {
      button.disabled = false;
      setFeedback("Не удалось удалить запись. Проверьте соединение.", true);
    }
  }
});

refreshRecords({ showLoading: true }).then(() => {
  if (IS_ADMIN) loadDate(toLocalISODate(new Date()));
});
