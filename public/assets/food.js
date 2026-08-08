"use strict";

import {
  escapeHTML,
  formatDate,
  pageConfig,
  renderHistoryChart,
  requestJSON,
  setupHistoryViewToggle,
  toLocalISODate,
  withCalendarRollingAverage
} from "./common.js";

const config = pageConfig();
const IS_ADMIN = config.isAdmin;
const API_URL = config.apiUrl;
const DEFAULT_PROFILE = Object.freeze(config.profile);

const form = document.querySelector("#food-form");
const dateInput = document.querySelector("#entry-date");
const dryInput = document.querySelector("#dry-food");
const wetInput = document.querySelector("#wet-food");
const dryHint = document.querySelector("#dry-hint");
const wetHint = document.querySelector("#wet-hint");
const result = document.querySelector("#result");
const totalValueOutput = document.querySelector("#total-value");
const comparisonOutput = document.querySelector("#comparison");
const statusOutput = document.querySelector("#status");
const breakdownOutput = document.querySelector("#breakdown");
const rangeNote = document.querySelector("#range-note");
const saveButton = document.querySelector("#save-button");
const feedback = document.querySelector("#feedback");
const historySection = document.querySelector("#history-section");
const historyList = document.querySelector("#history-list");
const historyChart = document.querySelector("#history-chart");
const historyViewToggle = document.querySelector(".history-view-toggle");
const historyAverage = document.querySelector("#history-average");
const historyAverageValue = document.querySelector("#history-average-value");
const historyAverageBadge = document.querySelector("#history-average-badge");

const state = {
  records: [],
  activeProfile: { ...DEFAULT_PROFILE }
};

const calorieFormatter = new Intl.NumberFormat("ru-RU", {
  minimumFractionDigits: 1,
  maximumFractionDigits: 1
});
const compactNumberFormatter = new Intl.NumberFormat("ru-RU", {
  maximumFractionDigits: 3
});

function yesterdayISO() {
  const date = new Date();
  date.setDate(date.getDate() - 1);
  return toLocalISODate(date);
}

function formatCalories(value) {
  return calorieFormatter.format(value);
}

function formatCompact(value) {
  return compactNumberFormatter.format(value);
}

function canUnit(value) {
  if (!Number.isInteger(value)) return "банки";
  const lastTwoDigits = Math.abs(value) % 100;
  const lastDigit = lastTwoDigits % 10;
  if (lastTwoDigits >= 11 && lastTwoDigits <= 14) return "банок";
  if (lastDigit === 1) return "банка";
  if (lastDigit >= 2 && lastDigit <= 4) return "банки";
  return "банок";
}

function numberFrom(input) {
  return Number.isFinite(input.valueAsNumber) ? input.valueAsNumber : 0;
}

function calorieChartPoints(records) {
  return withCalendarRollingAverage(records.map((record) => {
    const values = calculateValues(record.dryAmount, record.wetAmount, profileFromRecord(record));
    return {
      date: record.date,
      value: values.totalCalories,
      lowerBound: record.targetMin,
      upperBound: record.targetMax
    };
  }));
}

function renderCalorieChart(points, emptyMessage = "История пока пуста.", retryLabel = "") {
  renderHistoryChart(historyChart, {
    points,
    emptyMessage,
    retryLabel,
    ariaLabel: "Среднее за 7 дней, фактические калории и исторические границы нормы",
    averageLabel: "Среднее за 7 дней",
    seriesLabel: "Фактические значения",
    boundsLabel: "Границы нормы",
    axisLabel: "ккал",
    formatAxisValue: formatCompact,
    formatValue: (value) => `${formatCalories(value)} ккал`,
    pointLabel: (point) =>
      `${formatDate(point.date)}: ${formatCalories(point.value)} ккал. Среднее за 7 дней ${formatCalories(point.rollingAverage)} ккал. Норма ${formatCompact(point.lowerBound)}–${formatCompact(point.upperBound)} ккал.`,
    tooltipLines: (point) => [
      formatDate(point.date),
      `Точно: ${formatCalories(point.value)} ккал`,
      `Среднее за 7 дней: ${formatCalories(point.rollingAverage)} ккал`,
      `Норма: ${formatCompact(point.lowerBound)}–${formatCompact(point.upperBound)} ккал`
    ]
  });
}

function renderHistoryLoading() {
  historyAverage.hidden = true;
  historyList.innerHTML = '<p class="empty-history">Загружаем историю…</p>';
  renderCalorieChart([], "Загружаем историю…");
}

function renderHistoryError() {
  historyAverage.hidden = true;
  historyList.innerHTML = `
    <div class="empty-history">
      <p>Не удалось загрузить историю.</p>
      <button class="text-button" type="button" data-action="retry">Повторить</button>
    </div>`;
  renderCalorieChart([], "Не удалось загрузить историю.", "Повторить");
}

async function refreshRecords({ showLoading = false } = {}) {
  if (showLoading) renderHistoryLoading();

  try {
    const payload = await requestJSON(API_URL);
    if (!Array.isArray(payload.records)) {
      throw new Error("Сервер вернул некорректную историю");
    }

    state.records = payload.records;
    renderHistory();
    return true;
  } catch (error) {
    renderHistoryError();
    return false;
  }
}

function profileFromRecord(record) {
  return {
    catWeight: record.catWeight,
    dryName: record.dryName,
    dryCaloriesPerGram: record.dryCaloriesPerGram,
    wetName: record.wetName,
    wetCaloriesPerCan: record.wetCaloriesPerCan,
    targetMin: record.targetMin,
    targetMax: record.targetMax
  };
}

function calculateValues(dryAmount, wetAmount, profile) {
  const dryCalories = dryAmount * profile.dryCaloriesPerGram;
  const wetCalories = wetAmount * profile.wetCaloriesPerCan;
  const totalCalories = dryCalories + wetCalories;
  return {
    dryCalories,
    wetCalories,
    totalCalories,
    isInRange: totalCalories >= profile.targetMin && totalCalories <= profile.targetMax
  };
}

function updateLabels(profile) {
  dryHint.textContent = `${profile.dryName || "Сухой корм"} · ${formatCompact(profile.dryCaloriesPerGram)} ккал/г`;
  wetHint.textContent = `${profile.wetName || "Влажный корм"} · ${formatCompact(profile.wetCaloriesPerCan)} ккал/банка`;
  rangeNote.textContent = `Целевой диапазон: ${formatCompact(profile.targetMin)}–${formatCompact(profile.targetMax)} ккал`;
}

function showNeutralResult() {
  result.className = "result";
  totalValueOutput.textContent = "—";
  comparisonOutput.textContent = "";
  statusOutput.textContent = "Введите количество корма";
  breakdownOutput.textContent = "";
  breakdownOutput.hidden = true;
}

function updateResult() {
  const profile = state.activeProfile;
  updateLabels(profile);

  if (dryInput.value === "" && wetInput.value === "") {
    showNeutralResult();
    return null;
  }

  const values = calculateValues(numberFrom(dryInput), numberFrom(wetInput), profile);
  result.className = `result ${values.isInRange ? "in-range" : "out-of-range"}`;
  totalValueOutput.textContent = `${formatCalories(values.totalCalories)} ккал`;

  if (values.isInRange) {
    comparisonOutput.textContent = "";
    statusOutput.textContent = "В пределах нормы";
  } else if (values.totalCalories < profile.targetMin) {
    comparisonOutput.textContent = `< ${formatCompact(profile.targetMin)} ккал`;
    statusOutput.textContent = `Не хватает ${formatCalories(profile.targetMin - values.totalCalories)} ккал до нормы`;
  } else {
    comparisonOutput.textContent = `> ${formatCompact(profile.targetMax)} ккал`;
    statusOutput.textContent = `Превышение на ${formatCalories(values.totalCalories - profile.targetMax)} ккал`;
  }

  breakdownOutput.textContent = `Сухой: ${formatCalories(values.dryCalories)} ккал · влажный: ${formatCalories(values.wetCalories)} ккал`;
  breakdownOutput.hidden = false;
  return values;
}

function setFeedback(message, isError = false) {
  if (!feedback) return;
  feedback.textContent = message;
  feedback.classList.toggle("error", isError);
}

function loadDate(date) {
  const record = state.records.find((item) => item.date === date);
  dateInput.value = date;

  if (record) {
    state.activeProfile = profileFromRecord(record);
    dryInput.value = record.dryAmount;
    wetInput.value = record.wetAmount;
    if (saveButton) saveButton.textContent = "Обновить запись";
  } else {
    state.activeProfile = { ...DEFAULT_PROFILE };
    dryInput.value = "";
    wetInput.value = "";
    if (saveButton) saveButton.textContent = "Сохранить день";
  }

  setFeedback("");
  updateResult();
}

function validateEntry() {
  if (!dateInput.value) return "Выберите дату";
  if (dryInput.value === "" && wetInput.value === "") return "Введите количество хотя бы одного корма";
  return "";
}

function describeRange(totalCalories, targetMin, targetMax) {
  const isBelowRange = totalCalories < targetMin;
  const isInRange = !isBelowRange && totalCalories <= targetMax;

  if (isInRange) {
    return { isBelowRange, isInRange, badgeText: "В норме" };
  }

  const boundary = isBelowRange ? targetMin : targetMax;
  const deviationCalories = isBelowRange
    ? targetMin - totalCalories
    : totalCalories - targetMax;
  const deviationPercent = boundary > 0
    ? ` (${formatCalories(deviationCalories / boundary * 100)}%)`
    : "";

  return {
    isBelowRange,
    isInRange,
    badgeText: `${isBelowRange ? "Ниже" : "Выше"} нормы на ${formatCalories(deviationCalories)} ккал${deviationPercent}`
  };
}

function renderHistoryAverage(records, chartPoints) {
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
  const averages = recentRecords.reduce((accumulator, record) => {
    accumulator.targetMin += record.targetMin;
    accumulator.targetMax += record.targetMax;
    return accumulator;
  }, { targetMin: 0, targetMax: 0 });
  const recordCount = recentRecords.length;
  const averageCalories = chartPoints.at(-1).rollingAverage;
  const averageMin = averages.targetMin / recordCount;
  const averageMax = averages.targetMax / recordCount;
  const range = describeRange(averageCalories, averageMin, averageMax);

  historyAverageValue.textContent = `${formatCalories(averageCalories)} ккал`;
  historyAverageBadge.textContent = range.badgeText;
  historyAverageBadge.className = `badge ${range.isInRange ? "good" : "warning"}`;
  historyAverage.hidden = false;
}

function renderHistory() {
  const records = [...state.records].sort((a, b) => b.date.localeCompare(a.date));
  const chartPoints = calorieChartPoints(records);
  renderHistoryAverage(records, chartPoints);

  const emptyMessage = IS_ADMIN
    ? "Сохраните первый день — он появится здесь."
    : "История пока пуста.";
  renderCalorieChart(chartPoints, emptyMessage);

  if (!records.length) {
    historyList.innerHTML = `<p class="empty-history">${emptyMessage}</p>`;
    return;
  }

  historyList.innerHTML = records.map((record) => {
    const values = calculateValues(record.dryAmount, record.wetAmount, profileFromRecord(record));
    const range = describeRange(values.totalCalories, record.targetMin, record.targetMax);
    const comparison = range.isInRange
      ? ""
      : range.isBelowRange
        ? `&lt; ${formatCompact(record.targetMin)} ккал`
        : `&gt; ${formatCompact(record.targetMax)} ккал`;

    return `
      <details class="history-item">
        <summary class="history-summary">
          <span class="history-date"><time datetime="${record.date}">${formatDate(record.date)}</time></span>
          <span class="history-summary-total">${formatCalories(values.totalCalories)} ккал</span>
          <span class="badge ${range.isInRange ? "good" : "warning"}">${range.badgeText}</span>
        </summary>
        <div class="history-expanded">
          <p class="history-total">
            ${formatCalories(values.totalCalories)} ккал${comparison ? ` <span class="history-comparison">${comparison}</span>` : ""}
          </p>
          <p class="history-details">
            ${escapeHTML(record.dryName)}: ${formatCompact(record.dryAmount)} г ·
            ${escapeHTML(record.wetName)}: ${formatCompact(record.wetAmount)} ${canUnit(record.wetAmount)}<br>
            Вес: ${formatCompact(record.catWeight)} кг · Норма: ${formatCompact(record.targetMin)}–${formatCompact(record.targetMax)} ккал
          </p>
          ${IS_ADMIN ? `
            <div class="history-actions">
              <button class="text-button" type="button" data-action="edit" data-date="${record.date}">Изменить</button>
              <button class="text-button danger" type="button" data-action="delete" data-date="${record.date}">Удалить</button>
            </div>` : ""}
        </div>
      </details>`;
  }).join("");
}

form.addEventListener("input", (event) => {
  const input = event.target;
  if (input.type === "number" && input.value !== "" && input.valueAsNumber < 0) {
    input.value = "0";
  }
  setFeedback("");
  updateResult();
});

dateInput.addEventListener("change", () => loadDate(dateInput.value));

form.addEventListener("submit", async (event) => {
  event.preventDefault();
  if (!IS_ADMIN) return;

  const validationMessage = validateEntry();
  if (validationMessage) {
    setFeedback(validationMessage, true);
    return;
  }

  const record = {
    date: dateInput.value,
    dryAmount: numberFrom(dryInput),
    wetAmount: numberFrom(wetInput)
  };
  const isUpdate = state.records.some((item) => item.date === record.date);
  saveButton.disabled = true;
  setFeedback("Сохраняем…");

  try {
    await requestJSON(`${API_URL}?date=${encodeURIComponent(record.date)}`, {
      method: "PUT",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        dryAmount: record.dryAmount,
        wetAmount: record.wetAmount
      })
    });

    const refreshed = await refreshRecords();
    if (refreshed) loadDate(record.date);
    setFeedback(refreshed
      ? isUpdate ? "Запись обновлена" : "День сохранён"
      : "День сохранён, но историю не удалось обновить", !refreshed);
  } catch (error) {
    setFeedback("Не удалось сохранить запись. Проверьте соединение.", true);
  } finally {
    saveButton.disabled = false;
  }
});

historySection.addEventListener("click", async (event) => {
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

  if (action === "delete" && window.confirm(`Удалить запись за ${formatDate(date)}?`)) {
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

async function initialize() {
  setupHistoryViewToggle(historyViewToggle);
  dateInput.max = toLocalISODate(new Date());
  const initialDate = yesterdayISO();
  loadDate(initialDate);

  if (await refreshRecords({ showLoading: true })) {
    loadDate(initialDate);
  }
}

initialize();
