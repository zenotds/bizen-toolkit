"use strict";

var filebirdGallery = {
  escapeHtml: function (str) {
    return String(str == null ? "" : str)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#39;");
  },
  renderTopBarButtons: function () {
    var config =
      typeof filebirdGalleryConfig !== "undefined" ? filebirdGalleryConfig : {};
    var buttons = config.buttons;
    // Fallback to the default set of buttons when no config is localized.
    if (!buttons) {
      buttons = {
        close: { class: "pswp__button--close", title: "Close (Esc)" },
        share: { class: "pswp__button--share", title: "Share" },
        fs: { class: "pswp__button--fs", title: "Toggle fullscreen" },
        zoom: { class: "pswp__button--zoom", title: "Zoom in/out" },
      };
    }
    var html = "";
    for (var key in buttons) {
      if (!Object.prototype.hasOwnProperty.call(buttons, key)) {
        continue;
      }
      var button = buttons[key];
      // A falsy value hides the button entirely.
      if (!button) {
        continue;
      }
      var btnClass = button.class || "pswp__button--" + key;
      var btnTitle = button.title || "";
      html +=
        '<button class="pswp__button ' +
        btnClass +
        '" title="' +
        btnTitle +
        '"></button>';
    }
    return html;
  },
  getTemplate: function () {
    return `
    <div class="pswp" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="pswp__bg"></div>
    <div class="pswp__scroll-wrap">
        <div class="pswp__container">
            <div class="pswp__item"></div>
            <div class="pswp__item"></div>
            <div class="pswp__item"></div>
        </div>
        <div class="pswp__ui pswp__ui--hidden">
            <div class="pswp__top-bar">
                <div class="pswp__counter"></div>
                ${filebirdGallery.renderTopBarButtons()}
                <div class="pswp__preloader">
                    <div class="pswp__preloader__icn">
                      <div class="pswp__preloader__cut">
                        <div class="pswp__preloader__donut"></div>
                      </div>
                    </div>
                </div>
            </div>
            <div class="pswp__share-modal pswp__share-modal--hidden pswp__single-tap">
                <div class="pswp__share-tooltip"></div> 
            </div>
            <button class="pswp__button pswp__button--arrow--left" title="Previous (arrow left)">
            </button>
            <button class="pswp__button pswp__button--arrow--right" title="Next (arrow right)">
            </button>
            <div class="pswp__caption">
                <div class="pswp__caption__center"></div>
            </div>
        </div>
    </div>
    </div>`;
  },
  createGallery: function (gallerySelector) {
    if (!document.getElementsByClassName("pswp").length) {
      document.body.insertAdjacentHTML('beforeend', filebirdGallery.getTemplate());
    }
    filebirdGallery.initPhotoSwipeFromDOM(gallerySelector);
  },
  parseThumbnailElements: function (el) {
    var thumbElements = el.childNodes,
      numNodes = thumbElements.length,
      items = [],
      figureEl,
      figcaptionEl,
      liEl,
      linkEl,
      imgEl,
      item;

    for (var i = 0; i < numNodes; i++) {
      liEl = thumbElements[i];
      // linkEl = figureEl.children[0]; // <a> element
      imgEl = liEl.querySelector("img");
      figureEl = liEl.querySelector("figure");
      figcaptionEl = figureEl.querySelector("figcaption") || document.createElement("figcaption");
      item = {
        src: imgEl.getAttribute("src"),
        w: parseInt(imgEl.getAttribute("width"), 10),
        h: parseInt(imgEl.getAttribute("height"), 10),
        title: filebirdGallery.escapeHtml(imgEl.getAttribute("alt")) + ' <div class="fbv-gallery-caption">' + figcaptionEl.innerHTML + '</div>',
        msrc: imgEl.getAttribute("src"),
        el: figureEl,
      };
      items.push(item);
    }
    return items;
  },
  openPhotoSwipe: function (index, galleryElement, disableAnimation, fromURL) {
    var pswpElement = document.querySelectorAll(".pswp")[0],
      gallery,
      options,
      items;

    items = filebirdGallery.parseThumbnailElements(galleryElement);
    options = {
      galleryUID: galleryElement.getAttribute("data-pswp-uid"),
      getThumbBoundsFn: function (index) {
        var thumbnail = items[index].el.getElementsByTagName("img")[0],
          pageYScroll =
            window.pageYOffset || document.documentElement.scrollTop,
          rect = thumbnail.getBoundingClientRect();
        //get height of #wpadminbar
        var wpadminbarHeight = 0;
        if (document.getElementById("wpadminbar")) {
          wpadminbarHeight = document.getElementById("wpadminbar").offsetHeight;
        }
        return { x: rect.left, y: ((rect.top + pageYScroll) - wpadminbarHeight), w: rect.width };
      },
    };

    if (fromURL) {
      if (options.galleryPIDs) {
        for (var j = 0; j < items.length; j++) {
          if (items[j].pid == index) {
            options.index = j;
            break;
          }
        }
      } else {
        options.index = parseInt(index, 10) - 1;
      }
    } else {
      options.index = parseInt(index, 10);
    }

    if (isNaN(options.index)) {
      return;
    }

    if (disableAnimation) {
      options.showAnimationDuration = 0;
    }

    // Let the share menu sub-items be customized from PHP (filebird_gallery_share_buttons).
    if (
      typeof filebirdGalleryConfig !== "undefined" &&
      filebirdGalleryConfig.shareButtons &&
      filebirdGalleryConfig.shareButtons.length
    ) {
      options.shareButtons = filebirdGalleryConfig.shareButtons;
    }

    gallery = new PhotoSwipe(pswpElement, PhotoSwipeUI_Default, items, options);
    gallery.init();
  },
  onThumbnailsClick: function (e) {
    e = e || window.event;

    var eTarget = e.target || e.srcElement;

    if (eTarget.tagName.toLowerCase() === "a") {
      return; // Allow the default behavior of the link
    }

    e.preventDefault ? e.preventDefault() : (e.returnValue = false);

    var clickedListItem = eTarget.closest(".blocks-gallery-item");
    if (!clickedListItem) {
      return;
    }
    var clickedGallery = clickedListItem.parentNode,
      childNodes = clickedListItem.parentNode.childNodes,
      numChildNodes = childNodes.length,
      nodeIndex = 0,
      index;
    for (var i = 0; i < numChildNodes; i++) {
      if (childNodes[i].nodeType !== 1) {
        continue;
      }

      if (childNodes[i] === clickedListItem) {
        index = nodeIndex;
        break;
      }
      nodeIndex++;
    }

    if (index >= 0) {
      filebirdGallery.openPhotoSwipe(index, clickedGallery);
    }
    return false;
  },
  initPhotoSwipeFromDOM: function (gallerySelector) {
    var galleryElements = document.querySelectorAll(gallerySelector);
    for (var i = 0, l = galleryElements.length; i < l; i++) {
      galleryElements[i].setAttribute("data-pswp-uid", i + 1);
      galleryElements[i].onclick = filebirdGallery.onThumbnailsClick;
    }
  },
};

document.addEventListener("DOMContentLoaded", function () {
  filebirdGallery.createGallery(".filebird-block-filebird-gallery");
});
