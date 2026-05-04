(() => {
    const normalize = (value) =>
        (value || "")
            .toString()
            .toLowerCase()
            .normalize("NFD")
            .replace(/[\u0300-\u036f]/g, "")
            .trim();

    const initFilter = (select) => {
        const targetId = select.dataset.target || "";
        if (!targetId) {
            return;
        }

        const rankingWrapper = document.getElementById(targetId);
        if (!rankingWrapper) {
            return;
        }

        const tableBody = rankingWrapper.querySelector("tbody");
        if (!tableBody) {
            return;
        }

        const rows = Array.from(tableBody.querySelectorAll("tr[data-age-category]"));

        const isAdult = (category) => {
            const cat = normalize(category);
            return cat === "s" || cat.startsWith("v");
        };

        const applyFilter = () => {
            const mode = normalize(select.value);

            rows.forEach((row) => {
                if (mode !== "children") {
                    row.hidden = false;
                    return;
                }

                row.hidden = isAdult(row.dataset.ageCategory);
            });
        };

        select.addEventListener("change", applyFilter);
        applyFilter();
    };

    const boot = () => {
        document.querySelectorAll(".fftt_club_tools-ranking-filter").forEach(initFilter);
    };

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", boot);
    } else {
        boot();
    }
})();
