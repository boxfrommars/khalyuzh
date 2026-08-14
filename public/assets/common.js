"use strict";

export function pageConfig() {
  const node = document.querySelector("#page-config");
  if (!node) throw new Error("Page configuration is missing");
  return JSON.parse(node.textContent);
}

export function toLocalISODate(date) {
  const year = date.getFullYear();
  const month = String(date.getMonth() + 1).padStart(2, "0");
  const day = String(date.getDate()).padStart(2, "0");
  return `${year}-${month}-${day}`;
}

export const dateFormatter = new Intl.DateTimeFormat("ru-RU", {
  day: "numeric",
  month: "long",
  year: "numeric"
});

const chartDateFormatter = new Intl.DateTimeFormat("ru-RU", {
  day: "2-digit",
  month: "2-digit",
  year: "2-digit"
});
const SVG_NAMESPACE = "http://www.w3.org/2000/svg";
const DAY_IN_MILLISECONDS = 24 * 60 * 60 * 1000;
const chartStates = new WeakMap();
let chartId = 0;

export function formatDate(dateString) {
  return dateFormatter.format(new Date(`${dateString}T12:00:00`));
}

function chartTimestamp(dateString) {
  return Date.parse(`${dateString}T00:00:00Z`);
}

export function withCalendarRollingAverage(points, days = 7) {
  const orderedPoints = [...points]
    .map((point) => ({ ...point, timestamp: chartTimestamp(point.date) }))
    .filter((point) => Number.isFinite(point.timestamp) && Number.isFinite(point.value))
    .sort((a, b) => a.timestamp - b.timestamp);
  const windowDuration = Math.max(1, days) * DAY_IN_MILLISECONDS;
  let windowStart = 0;
  let windowTotal = 0;

  return orderedPoints.map((point, index) => {
    windowTotal += point.value;
    const earliestTimestamp = point.timestamp - windowDuration + DAY_IN_MILLISECONDS;

    while (orderedPoints[windowStart].timestamp < earliestTimestamp) {
      windowTotal -= orderedPoints[windowStart].value;
      windowStart += 1;
    }

    return {
      ...point,
      rollingAverage: windowTotal / (index - windowStart + 1),
      rollingCount: index - windowStart + 1
    };
  });
}

function createSvgElement(name, attributes = {}, textContent = "") {
  const element = document.createElementNS(SVG_NAMESPACE, name);
  Object.entries(attributes).forEach(([key, value]) => {
    element.setAttribute(key, String(value));
  });
  if (textContent !== "") element.textContent = textContent;
  return element;
}

function niceStep(value) {
  const exponent = Math.floor(Math.log10(value));
  const magnitude = 10 ** exponent;
  const fraction = value / magnitude;
  const niceFraction = fraction <= 1 ? 1 : fraction <= 2 ? 2 : fraction <= 5 ? 5 : 10;
  return niceFraction * magnitude;
}

function chartScale(values, nonNegative) {
  let minimum = Math.min(...values);
  let maximum = Math.max(...values);

  if (minimum === maximum) {
    const padding = Math.max(Math.abs(minimum) * 0.05, 0.1);
    minimum -= padding;
    maximum += padding;
  } else {
    const padding = (maximum - minimum) * 0.1;
    minimum -= padding;
    maximum += padding;
  }

  let step = niceStep((maximum - minimum) / 4);
  let scaleMinimum = Math.floor(minimum / step) * step;
  let scaleMaximum = Math.ceil(maximum / step) * step;

  if (nonNegative && scaleMinimum < 0) scaleMinimum = 0;

  let tickCount = Math.round((scaleMaximum - scaleMinimum) / step) + 1;
  if (tickCount > 7) {
    step *= 2;
    scaleMinimum = Math.floor(minimum / step) * step;
    scaleMaximum = Math.ceil(maximum / step) * step;
    if (nonNegative && scaleMinimum < 0) scaleMinimum = 0;
    tickCount = Math.round((scaleMaximum - scaleMinimum) / step) + 1;
  }

  const ticks = Array.from({ length: tickCount }, (_, index) => scaleMinimum + step * index);
  return { minimum: scaleMinimum, maximum: scaleMaximum, ticks };
}

function linePath(points, key, xPosition, yPosition) {
  let previousPoint = null;

  return points.reduce((path, point) => {
    if (!Number.isFinite(point[key])) {
      previousPoint = null;
      return path;
    }

    const startsSegment = previousPoint === null;
    previousPoint = point;
    return `${path}${path ? " " : ""}${startsSegment ? "M" : "L"} ${xPosition(point)} ${yPosition(point[key])}`;
  }, "");
}

function stepPath(points, key, xPosition, yPosition, left, right) {
  const boundaryPoints = points.filter((point) => Number.isFinite(point[key]));
  if (!boundaryPoints.length) return "";
  if (boundaryPoints.length === 1) {
    return `M ${left} ${yPosition(boundaryPoints[0][key])} H ${right}`;
  }

  let path = `M ${xPosition(boundaryPoints[0])} ${yPosition(boundaryPoints[0][key])}`;
  boundaryPoints.slice(1).forEach((point) => {
    path += ` H ${xPosition(point)} V ${yPosition(point[key])}`;
  });
  return path;
}

function chartStatus(container, message, retryLabel) {
  if (!retryLabel) {
    const status = document.createElement("p");
    status.className = "empty-history";
    status.textContent = message;
    container.replaceChildren(status);
    return;
  }

  const status = document.createElement("div");
  status.className = "empty-history";
  const text = document.createElement("p");
  text.textContent = message;
  const retry = document.createElement("button");
  retry.className = "text-button";
  retry.type = "button";
  retry.dataset.action = "retry";
  retry.textContent = retryLabel;
  status.append(text, retry);
  container.replaceChildren(status);
}

function drawHistoryChart(container, state) {
  const options = state.options;
  const allPoints = (Array.isArray(options.points) ? options.points : [])
    .map((point) => ({ ...point, timestamp: chartTimestamp(point.date) }))
    .filter((point) => Number.isFinite(point.timestamp) && Number.isFinite(point.value))
    .sort((a, b) => a.timestamp - b.timestamp);
  const maximumPoints = options.maxPoints === null
    ? allPoints.length
    : Number.isInteger(options.maxPoints) && options.maxPoints > 0 ? options.maxPoints : 40;
  const points = allPoints.slice(-maximumPoints);
  const showAverage = options.showAverage !== false;

  if (!points.length) {
    chartStatus(container, options.emptyMessage || "История пока пуста.", options.retryLabel);
    return;
  }

  const measuredWidth = Math.floor(container.getBoundingClientRect().width);
  const width = Math.max(280, measuredWidth || 600);
  const height = width < 440 ? 280 : 320;
  state.width = measuredWidth;

  const margin = { top: 28, right: 18, bottom: 46, left: 58 };
  const plotWidth = width - margin.left - margin.right;
  const plotHeight = height - margin.top - margin.bottom;
  const values = points.flatMap((point) => [
    point.value,
    ...(showAverage && Number.isFinite(point.rollingAverage) ? [point.rollingAverage] : []),
    ...(Number.isFinite(point.lowerBound) ? [point.lowerBound] : []),
    ...(Number.isFinite(point.upperBound) ? [point.upperBound] : [])
  ]);
  const yScale = chartScale(values, options.nonNegative !== false);
  let firstTimestamp = points[0].timestamp;
  let lastTimestamp = points.at(-1).timestamp;
  if (firstTimestamp === lastTimestamp) {
    firstTimestamp -= DAY_IN_MILLISECONDS / 2;
    lastTimestamp += DAY_IN_MILLISECONDS / 2;
  }

  const xPosition = (point) => margin.left
    + (point.timestamp - firstTimestamp) / (lastTimestamp - firstTimestamp) * plotWidth;
  const yPosition = (value) => margin.top
    + (yScale.maximum - value) / (yScale.maximum - yScale.minimum) * plotHeight;
  const formatAxisValue = options.formatAxisValue || ((value) => String(value));
  const formatValue = options.formatValue || ((value) => String(value));

  chartId += 1;
  const titleId = `history-chart-title-${chartId}`;
  const descriptionId = `history-chart-description-${chartId}`;
  const figure = document.createElement("figure");
  figure.className = "chart-figure";

  const legend = document.createElement("div");
  legend.className = "chart-legend";
  const legendItems = [];
  if (showAverage) {
    legendItems.push({
      label: options.averageLabel || "Среднее за 7 дней",
      className: "chart-legend-average"
    });
  }
  if (options.seriesLabel) {
    legendItems.push({ label: options.seriesLabel, className: "chart-legend-series" });
  }
  if (points.some((point) => Number.isFinite(point.lowerBound) || Number.isFinite(point.upperBound))) {
    legendItems.push({ label: options.boundsLabel || "Границы нормы", className: "chart-legend-boundaries" });
  }
  legendItems.forEach((item) => {
    const legendItem = document.createElement("span");
    legendItem.className = "chart-legend-item";
    const swatch = document.createElement("span");
    swatch.className = `chart-legend-swatch ${item.className}`;
    swatch.setAttribute("aria-hidden", "true");
    legendItem.append(swatch, document.createTextNode(item.label));
    legend.append(legendItem);
  });

  const plot = document.createElement("div");
  plot.className = "chart-plot";
  const svg = createSvgElement("svg", {
    class: "history-chart-svg",
    viewBox: `0 0 ${width} ${height}`,
    width,
    height,
    role: "img",
    "aria-labelledby": `${titleId} ${descriptionId}`
  });
  svg.append(
    createSvgElement("title", { id: titleId }, options.ariaLabel || "График истории"),
    createSvgElement(
      "desc",
      { id: descriptionId },
      options.ariaDescription
        || `Показано ${points.length} из ${allPoints.length} записей с ${formatDate(points[0].date)} по ${formatDate(points.at(-1).date)}.${showAverage ? " Основная линия показывает среднее за 7 дней." : ""}`
    )
  );

  const grid = createSvgElement("g", { class: "chart-grid", "aria-hidden": "true" });
  yScale.ticks.forEach((tick) => {
    const y = yPosition(tick);
    grid.append(
      createSvgElement("line", { x1: margin.left, y1: y, x2: width - margin.right, y2: y }),
      createSvgElement("text", {
        x: margin.left - 10,
        y: y + 4,
        "text-anchor": "end"
      }, formatAxisValue(tick))
    );
  });
  grid.append(createSvgElement("line", {
    x1: margin.left,
    y1: height - margin.bottom,
    x2: width - margin.right,
    y2: height - margin.bottom,
    class: "chart-axis-line"
  }));
  svg.append(grid);

  if (options.axisLabel) {
    svg.append(createSvgElement("text", {
      x: margin.left,
      y: 16,
      class: "chart-axis-title",
      "aria-hidden": "true"
    }, options.axisLabel));
  }

  const dateLabelIndexes = [...new Set([0, Math.floor((points.length - 1) / 2), points.length - 1])];
  const dateLabels = createSvgElement("g", { class: "chart-date-labels", "aria-hidden": "true" });
  dateLabelIndexes.forEach((pointIndex, labelIndex) => {
    const point = points[pointIndex];
    const x = xPosition(point);
    const textAnchor = dateLabelIndexes.length === 1
      ? "middle"
      : labelIndex === 0 ? "start" : labelIndex === dateLabelIndexes.length - 1 ? "end" : "middle";
    dateLabels.append(
      createSvgElement("line", {
        x1: x,
        y1: height - margin.bottom,
        x2: x,
        y2: height - margin.bottom + 5
      }),
      createSvgElement("text", {
        x,
        y: height - 16,
        "text-anchor": textAnchor
      }, chartDateFormatter.format(new Date(point.timestamp)))
    );
  });
  svg.append(dateLabels);

  const lowerPath = stepPath(points, "lowerBound", xPosition, yPosition, margin.left, width - margin.right);
  const upperPath = stepPath(points, "upperBound", xPosition, yPosition, margin.left, width - margin.right);
  if (lowerPath) {
    svg.append(createSvgElement("path", {
      d: lowerPath,
      class: "chart-boundary chart-boundary-lower",
      "aria-hidden": "true"
    }));
  }
  if (upperPath) {
    svg.append(createSvgElement("path", {
      d: upperPath,
      class: "chart-boundary chart-boundary-upper",
      "aria-hidden": "true"
    }));
  }
  if (points.length > 1) {
    const dailyPath = linePath(points, "value", xPosition, yPosition);
    svg.append(createSvgElement("path", {
      d: dailyPath,
      class: "chart-daily-line",
      "aria-hidden": "true"
    }));
    if (showAverage) {
      svg.append(createSvgElement("path", {
        d: linePath(points, "rollingAverage", xPosition, yPosition),
        class: "chart-average-line",
        "aria-hidden": "true"
      }));
    }
  }

  const tooltip = document.createElement("div");
  tooltip.className = "chart-tooltip";
  tooltip.hidden = true;
  tooltip.setAttribute("role", "status");

  const hideTooltip = () => {
    tooltip.hidden = true;
  };
  const showTooltip = (point) => {
    const x = xPosition(point);
    const y = yPosition(point.value);
    const lines = options.tooltipLines
      ? options.tooltipLines(point)
      : [formatDate(point.date), formatValue(point.value)];
    tooltip.replaceChildren(...lines.map((line, index) => {
      const row = document.createElement(index === 0 ? "strong" : "span");
      row.textContent = line;
      return row;
    }));
    tooltip.style.left = `${x}px`;
    tooltip.style.top = `${y}px`;
    tooltip.dataset.align = x < width * 0.22 ? "start" : x > width * 0.78 ? "end" : "center";
    tooltip.hidden = false;
  };

  const pointGroup = createSvgElement("g", { class: "chart-points" });
  const pointLimit = Number.isInteger(options.pointLimit) && options.pointLimit >= 0
    ? options.pointLimit
    : Number.POSITIVE_INFINITY;
  if (points.length <= pointLimit) points.forEach((point) => {
    const accessibleLabel = options.pointLabel
      ? options.pointLabel(point)
      : `${formatDate(point.date)}: ${formatValue(point.value)}`;
    const target = createSvgElement("g", {
      class: "chart-point-target",
      tabindex: "0",
      role: "button",
      "aria-label": accessibleLabel
    });
    target.append(
      createSvgElement("circle", {
        class: "chart-point-hit",
        cx: xPosition(point),
        cy: yPosition(point.value),
        r: 13
      }),
      createSvgElement("circle", {
        class: "chart-point-dot",
        cx: xPosition(point),
        cy: yPosition(point.value),
        r: 3.5
      })
    );
    target.addEventListener("mouseenter", () => showTooltip(point));
    target.addEventListener("mouseleave", () => {
      if (document.activeElement !== target) hideTooltip();
    });
    target.addEventListener("focus", () => showTooltip(point));
    target.addEventListener("blur", hideTooltip);
    target.addEventListener("click", () => {
      target.focus();
      showTooltip(point);
    });
    target.addEventListener("keydown", (event) => {
      if (event.key === "Escape") {
        hideTooltip();
        target.blur();
      }
    });
    pointGroup.append(target);
  });
  svg.append(pointGroup);
  plot.append(svg, tooltip);

  const caption = document.createElement("figcaption");
  caption.className = "chart-caption";
  caption.textContent = allPoints.length > points.length
    ? `Показаны последние ${points.length} из ${allPoints.length} записей.`
    : `Записей на графике: ${points.length}.`;
  figure.append(legend, plot, caption);
  container.replaceChildren(figure);
}

export function renderHistoryChart(container, options) {
  let state = chartStates.get(container);
  if (!state) {
    state = { options, width: 0, observer: null };
    if (typeof ResizeObserver !== "undefined") {
      state.observer = new ResizeObserver((entries) => {
        const width = Math.floor(entries[0].contentRect.width);
        if (width > 0 && Math.abs(width - state.width) > 1) {
          drawHistoryChart(container, state);
        }
      });
      state.observer.observe(container);
    }
    chartStates.set(container, state);
  }

  state.options = options;
  drawHistoryChart(container, state);
}

export function setupHistoryViewToggle(toggle) {
  const tabs = [...toggle.querySelectorAll('[role="tab"]')];
  const activate = (tab) => {
    tabs.forEach((candidate) => {
      const selected = candidate === tab;
      const panel = document.querySelector(`#${candidate.getAttribute("aria-controls")}`);
      candidate.setAttribute("aria-selected", String(selected));
      candidate.tabIndex = selected ? 0 : -1;
      if (panel) panel.hidden = !selected;
    });
  };

  tabs.forEach((tab, index) => {
    tab.addEventListener("click", () => activate(tab));
    tab.addEventListener("keydown", (event) => {
      let nextIndex = null;
      if (event.key === "ArrowRight" || event.key === "ArrowDown") {
        nextIndex = (index + 1) % tabs.length;
      } else if (event.key === "ArrowLeft" || event.key === "ArrowUp") {
        nextIndex = (index - 1 + tabs.length) % tabs.length;
      } else if (event.key === "Home") {
        nextIndex = 0;
      } else if (event.key === "End") {
        nextIndex = tabs.length - 1;
      }

      if (nextIndex === null) return;
      event.preventDefault();
      activate(tabs[nextIndex]);
      tabs[nextIndex].focus();
    });
  });

  const selectedTab = tabs.find((tab) => tab.getAttribute("aria-selected") === "true") || tabs[0];
  if (selectedTab) activate(selectedTab);
}

export function escapeHTML(value) {
  return String(value)
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#039;");
}

export async function requestJSON(url, options = {}) {
  const response = await fetch(url, {
    cache: "no-store",
    ...options,
    headers: {
      Accept: "application/json",
      ...options.headers
    }
  });

  if (response.status === 204) return null;

  let payload;
  try {
    payload = await response.json();
  } catch (error) {
    throw new Error("Сервер вернул некорректный ответ");
  }

  if (!response.ok) {
    throw new Error(payload.error || "Запрос завершился ошибкой");
  }

  return payload;
}
