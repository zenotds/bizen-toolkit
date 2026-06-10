/*
 * Menu Enhancer
 */

(($) => {
	/*
	 * Scroll indicator — proportional section markers for top-level menu items.
	 */
	var scroller = (() => {
		var $el = null;
		var offsets = [];
		var current;
		var hasScrolled = false;
		var page, contentFn;

		function norm() {
			return $("#menu-to-edit").offset().top + 35;
		}

		function handleHeight() {
			var ho = $(window).height(),
				hi = $("body").height();
			return (ho / hi) * ho - norm();
		}

		function getCurrent() {
			var st = $(window).scrollTop() + handleHeight() / 4,
				i = 0;
			for (var l = offsets.length; i < l; i++) {
				if (!offsets[i + 1]) return i;
				if (st <= offsets[i]) return i;
				if (st > offsets[i] && st <= offsets[i + 1]) return i;
			}
			return i;
		}

		function scrollTo(o) {
			o -= 35;
			var duration = Math.min(
				Math.abs(((offsets[current] || 0) - o) / 2),
				1000,
			);
			$("html, body").stop(true).animate({ scrollTop: o }, duration);
		}

		function update() {
			var i = getCurrent();
			if (i === current) return;
			current = i;
			$el
				.children()
				.eq(i)
				.addClass("bznme-scroller-current")
				.siblings()
				.removeClass("bznme-scroller-current");
		}

		function draw() {
			if (!$el) return;

			var n = norm();
			var h_win = $(window).height();
			var h_doc = $("#post-body").height() + n;
			var $items = $("body").find(page);
			var s = [];

			offsets = [];
			$el.detach().empty();

			for (var i = 0, len = $items.length; i < len; i++) {
				var $p = $items.eq(i);
				var $next = $items.eq(i + 1);
				var top = $p.offset().top;
				var os = ((top - n) / h_doc) * h_win;
				var ph;

				if ($next.length === 0) {
					var $last = $(".menu-item").last();
					ph = (($last.offset().top + $last.height() - top) / h_doc) * h_win;
				} else {
					ph = (($next.offset().top - top) / h_doc) * h_win - 10;
				}

				if (ph < 5) {
					ph = 5;
					os -= ph;
				}

				s.push(
					'<div class="bznme-scroller' +
						(i === current ? " bznme-scroller-current" : "") +
						'" style="height:' +
						ph +
						"px;top:" +
						os +
						'px;">' +
						contentFn(i, $p) +
						"</div>",
				);
				offsets.push(top);
			}

			$el[0].innerHTML = s.join("");
			$el.appendTo("body");
		}

		return {
			init: (options) => {
				page = options.page || ".page";
				contentFn =
					options.content ||
					((i) => '<span class="bznme-scroller-span">' + (i + 1) + "</span>");

				$el = $('<div class="bznme-scroller-set"/>').appendTo("body");

				draw();
				update();

				$(window)
					.on("resize.bznme", draw)
					.on("scroll.bznme", () => {
						hasScrolled = true;
					});

				setInterval(() => {
					if (!hasScrolled) return;
					hasScrolled = false;
					update();
				}, 250);

				$el
					.on("mouseenter mouseleave", ".bznme-scroller", function () {
						$(this).toggleClass("bznme-scroller-hover");
					})
					.on("click", ".bznme-scroller", function (e) {
						e.preventDefault();
						var o = $("body").find(page).eq($(this).index()).offset().top;
						scrollTo(o);
					});
			},
			draw: draw,
		};
	})();

	/*
	 * Per-item enhancer plugin.
	 */
	$.bizenMenuEnhancer = function (el) {
		var plugin = this;
		plugin.item_states = {};

		var init = () => {
			plugin.el = el;
			plugin.$menu = $(el);
			plugin.$menuItems = plugin.$menu.find("li.menu-item");

			plugin.$menuItems.each(function () {
				enhance_item($(this));
			});

			plugin.x = 0;
			plugin.y = 0;
			plugin.primed = false;

			plugin.$menu.on("mousedown", "li.menu-item", function (e) {
				plugin.x = e.clientX;
				plugin.y = e.clientY;
				$(this).on("mousemove.bznme", (e2) => {
					if (
						!plugin.primed &&
						(Math.abs(e2.clientX - plugin.x) > 15 ||
							Math.abs(e2.clientY - plugin.y) > 20)
					) {
						plugin.primed = true;
					}
				});
			});

			plugin.$menu.on("mouseup", "li.menu-item", function () {
				$(this).off("mousemove.bznme");
				if (!plugin.primed) return;
				var $dropped = $(this),
					$startParents = get_parents($dropped);
				setTimeout(() => {
					refresh("parents", $dropped, $startParents);
				}, 400);
				plugin.primed = false;
			});

			plugin.itemcount = 0;

			$(".submit-add-to-menu").click(() => {
				plugin.itemcount = plugin.$menuItems.length;
				listen_for_new_item(0);
			});

			$(".item-delete").click(function () {
				var $item = $(this).parents("li.menu-item"),
					$sp = get_parents($item);
				setTimeout(() => {
					refresh("delete", $item, $sp);
				}, 500);
			});

			$(".item-edit, .wpmega-showhide-menu-ops").click(function () {
				var $item = $(this).parents("li.menu-item");
				var $items = $item
					.prevUntil("li.menu-item-depth-0", "li.menu-item")
					.addBack()
					.prev("li.menu-item-depth-0")
					.addBack();
				setTimeout(() => {
					$items.each((k, li) => {
						draw_highlights($(li), get_children($(li)));
					});
				}, 500);
			});

			scroller.init({
				page: "li.menu-item-depth-0",
				content: (i, $page) =>
					'<div class="bznme-scroller-span"><strong>' +
					$page.find(".item-title").text() +
					"</strong><br/>#" +
					$page.attr("id") +
					"</div>",
			});

			plugin.recallState();
		};

		var enhance_item = ($item) => {
			var $enhancer = $item.find(".bznme-enhancer");
			if ($enhancer.length === 0) {
				$enhancer = create_enhancer($item);
			}

			var $kids = get_children($item);
			var numkids = $kids.filter(
				".menu-item-depth-" + (get_depth($item) + 1),
			).length;
			var numdesc = $kids.length;

			if (
				$enhancer.hasClass("bznme-enhancer-" + numkids) &&
				$enhancer.hasClass("bznme-enhancer-desc-" + numdesc)
			) {
				return;
			}

			$enhancer
				.removeClass()
				.addClass(
					"bznme-enhancer bznme-enhancer-" +
						numkids +
						" bznme-enhancer-desc-" +
						numdesc,
				);

			$enhancer
				.find(".bznme-children")
				.text(numkids)
				.attr(
					"title",
					numkids + " children, " + numdesc + " total descendants",
				);

			draw_highlights($item, $kids);
		};

		var create_enhancer = ($item) => {
			var $enhancer = $(
				'<span class="bznme-enhancer">' +
					'<span class="bznme-expando bznme-open" title="Expand/Contract">+</span>' +
					'<span class="bznme-children"></span>' +
					'<span class="bznme-highlight-toggle dashicons dashicons-admin-appearance" title="Highlight group"></span>' +
					"</span>",
			);

			$('<span class="bznme-item-id">')
				.text("#" + $item.attr("id"))
				.appendTo($item.find(".menu-item-handle"));

			$item.find("> .menu-item-bar").append($enhancer);

			// Prevent highlight-toggle clicks from bubbling up to the expand/contract handler.
			$enhancer.find(".bznme-highlight-toggle").on("click", (e) => {
				e.stopPropagation();
			});

			$enhancer.click(() => {
				if ($item.hasClass("bznme-contracted")) toggle_expand($item);
				else toggle_contract($item);
			});

			return $enhancer;
		};

		var toggle_expand = ($item) => {
			var $children = get_children($item);
			var left = $children.length;

			$children.slideDown(() => {
				if (--left === 0) scroller.draw();
			});
			$item.toggleClass("bznme-contracted");
			$item
				.find(".bznme-expando")
				.addClass("bznme-open");

			plugin.itemStatus($item.attr("id"), false);
		};

		var toggle_contract = ($item) => {
			var $children = get_children($item);
			var left = $children.length;

			$children.slideUp("normal", () => {
				if (--left === 0) scroller.draw();
			});
			$children
				.removeClass("bznme-contracted")
				.find(".bznme-expando")
				.addClass("bznme-open");
			$item.toggleClass("bznme-contracted");
			$item
				.find(".bznme-expando")
				.removeClass("bznme-open");

			plugin.itemStatus($item.attr("id"), true);
		};

		var get_children = ($item) => {
			var depth = get_depth($item),
				selector = "";
			while (depth >= 0) {
				selector += ".menu-item-depth-" + depth;
				if (depth > 0) selector += ", ";
				depth--;
			}
			return $item.nextUntil(selector);
		};

		var get_parents = ($item) => {
			var depth = get_depth($item);
			if (depth === 0) return $item;

			var selector = "";
			depth--;
			while (depth >= 0) {
				selector += ".menu-item-depth-" + depth;
				if (depth > 0) selector += ", ";
				depth--;
			}

			var $parents = $item,
				$prev = $item.prev(".menu-item");
			while ($prev.length > 0) {
				if ($prev.is(selector)) {
					$parents = $parents.add($prev);
					if ($prev.hasClass("menu-item-depth-0")) break;
				}
				$prev = $prev.prev(".menu-item");
			}
			return $parents;
		};

		var get_depth = ($item) => {
			var m = ($item.attr("class") || "").match(/\bmenu-item-depth-(\d+)\b/);
			return m ? parseInt(m[1], 10) : 0;
		};

		var draw_highlights = ($item, $kids) => {
			var $highlight = $item.find(".bznme-highlight");
			var top = $item.position().top;
			var height = $item.outerHeight() + 10;
			var $last = $kids.last();

			if ($last.length > 0) {
				height = $last.position().top + $last.outerHeight() - top + 7;
			}

			if ($highlight.length > 0) {
				$highlight.css({ height: height + "px" });
			} else {
				$highlight = $('<div class="bznme-highlight">')
					.css({ top: "-7px", height: height + "px" })
					.hide();
				$item.append($highlight);
				$item.find(".bznme-highlight-toggle").hover(
					() => {
						$highlight.fadeIn("fast");
					},
					() => {
						$highlight.fadeOut("fast");
					},
				);
			}
		};

		var refresh = (set, $item, $items) => {
			switch (set) {
				case "parents":
					$items = $items.add(get_parents($item));
					break;
				case "delete":
					break;
				default:
					$items = plugin.$menuItems;
			}
			$items.each(function () {
				enhance_item($(this));
			});
			scroller.draw();
		};

		function listen_for_new_item(retries) {
			if (retries > 40) return;
			var $group = $("#menu-to-edit li.menu-item");
			var newcount = $group.length;
			if (newcount > plugin.itemcount) {
				plugin.itemcount = newcount;
				plugin.$menuItems = $group;
				refresh();
				return;
			}
			setTimeout(() => {
				listen_for_new_item(retries + 1);
			}, 500);
		}

		plugin.refreshHighlights = () => {
			plugin.$menuItems.each((k, li) => {
				draw_highlights($(li), get_children($(li)));
			});
		};

		plugin.contractAll = () => {
			var $top = plugin.$menu.find(".menu-item-depth-0");
			var $items = plugin.$menu.find(".menu-item:not(.menu-item-depth-0)");
			var n = $items.length;
			$items.slideUp("fast", () => {
				if (--n === 0) {
					$top
						.addClass("bznme-contracted")
						.find(".bznme-expando")
						.removeClass("bznme-open");
					refresh();
				}
			});
			plugin.collapseItemStates();
		};

		plugin.expandAll = () => {
			var $items = plugin.$menu.find(".menu-item:not(.menu-item-depth-0)");
			var n = $items.length;
			$items.slideDown("fast", () => {
				if (--n === 0) {
					plugin.$menuItems
						.removeClass("bznme-contracted")
						.find(".bznme-expando")
						.addClass("bznme-open");
					refresh();
				}
			});
			plugin.clearItemStates();
		};

		plugin.itemStatus = (item_id, closed) => {
			if (!window.localStorage) return;
			if (typeof closed === "undefined") return plugin.item_states[item_id];
			if (closed) plugin.item_states[item_id] = true;
			else delete plugin.item_states[item_id];
			plugin.storeItemStates();
		};

		plugin.storeItemStates = () => {
			if (window.localStorage) {
				window.localStorage.setItem(
					"bznme_item_states",
					JSON.stringify(plugin.item_states),
				);
			}
		};

		plugin.clearItemStates = () => {
			if (window.localStorage) {
				plugin.item_states = {};
				plugin.storeItemStates();
			}
		};

		plugin.collapseItemStates = () => {
			if (!window.localStorage) return;
			plugin.$menu.find(".menu-item-depth-0").each(function () {
				plugin.item_states[$(this).attr("id")] = true;
			});
			plugin.storeItemStates();
		};

		plugin.recallState = () => {
			if (!window.localStorage) return;
			var raw = window.localStorage.getItem("bznme_item_states");
			try {
				plugin.item_states = raw ? JSON.parse(raw) : {};
			} catch (e) {
				plugin.item_states = {};
			}
			jQuery.each(plugin.item_states, (k) => {
				toggle_contract($("#" + k));
			});
		};

		init();
	};

	$.fn.bizenMenuEnhancer = function () {
		return this.each(function () {
			if ($(this).data("bizenMenuEnhancer") === undefined) {
				$(this).data("bizenMenuEnhancer", new $.bizenMenuEnhancer(this));
			}
		});
	};
})(jQuery);

jQuery(document).ready(($) => {
	var $menu = $("#menu-to-edit");
	if ($menu.length === 0) return;

	$menu.bizenMenuEnhancer();
	var $mme = $menu.data("bizenMenuEnhancer");

	var buffer = 35;
	var off = $menu.offset().top + buffer;

	$("#post-body").css("min-height", $(window).height() - off);

	$(window).scroll(() => {
		var top = $(window).scrollTop();
		var pos = top > off - buffer ? buffer : off - top;
		$(".bznme-scroller-set, .bznme-toolbar").css("top", pos + "px");
	});

	/* ---- Toolbar ---- */
	var $toolbar = $(
		'<div class="bznme-toolbar" role="toolbar" aria-label="Menu Enhancer">',
	).appendTo("body");

	var $toggleAll = $(
		'<span class="bznme-button bznme-toggle-all bznme-toggle-on" title="Collapse All"><span class="dashicons dashicons-arrow-up-alt2"></span></span>',
	);
	$toggleAll.click(function () {
		var $btn = $(this);
		if ($btn.hasClass("bznme-toggle-on")) {
			$mme.contractAll();
			$btn
				.toggleClass("bznme-toggle-on")
				.attr("title", "Expand All")
				.find(".dashicons")
				.removeClass("dashicons-arrow-up-alt2")
				.addClass("dashicons-arrow-down-alt2");
		} else {
			$mme.expandAll();
			$btn
				.toggleClass("bznme-toggle-on")
				.attr("title", "Collapse All")
				.find(".dashicons")
				.removeClass("dashicons-arrow-down-alt2")
				.addClass("dashicons-arrow-up-alt2");
		}
	});
	$toolbar.append($toggleAll);

	var $toggleIDs = $(
		'<span class="bznme-button bznme-toggle-ids" title="Show/Hide Menu Item IDs"><span class="dashicons dashicons-tag"></span></span>',
	);
	$toggleIDs.click(function () {
		$(".bznme-item-id").toggle();
		$(this).toggleClass("bznme-toggle-on");
	});
	$toolbar.append($toggleIDs);

	/* ---- Initial positioning ---- */
	$(".bznme-scroller-set, .bznme-toolbar").css("top", off + "px");

	/* ---- Shift+hover on scroller section scrolls to it while dragging ---- */
	$(".bznme-scroller-set").on("mouseenter", ".bznme-scroller", function (e) {
		if (e.shiftKey) $(this).click();
	});
});
