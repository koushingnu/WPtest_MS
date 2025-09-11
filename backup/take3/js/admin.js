jQuery(document).ready(function ($) {
  // タブ切り替え処理
  $(".nav-tab").on("click", function (e) {
    e.preventDefault();

    var $this = $(this);
    var tabId = $this.attr("href").split("tab=")[1];

    $(".nav-tab").removeClass("nav-tab-active");
    $this.addClass("nav-tab-active");

    $("#aiap-content").html(
      '<div class="spinner is-active" style="float:none;width:100%;height:100px;padding:20px 0;text-align:center;background:rgba(255,255,255,0.8);"></div>'
    );

    $.post(
      aiapAdmin.ajaxurl,
      {
        action: "aiap_load_tab",
        tab: tabId,
        nonce: aiapAdmin.nonce,
      },
      function (response) {
        if (response.success) {
          $("#aiap-content").html(response.data);
          history.pushState({}, "", "?page=aiap-lite&tab=" + tabId);
        }
      }
    );
  });

  // 中項目の管理
  if ($("#sub-topics-manager").length) {
    // 新規項目の追加
    $("#add-topic-btn").on("click", function () {
      var title = $("#new-topic-title").val().trim();
      var keywords = $("#new-topic-keywords").val().trim();

      if (!title) {
        alert("タイトルを入力してください");
        return;
      }

      var id = "topic_" + Date.now();
      var newItem = $(`
        <div class="topic-item" data-id="${id}" style="margin-bottom: 10px; padding: 10px; border: 1px solid #ddd; border-radius: 4px; display: flex; align-items: center; gap: 10px;">
          <div style="flex: 1;">
            <input type="hidden" name="aiap_lite_settings[sub_topics][${id}][id]" value="${id}">
            <input type="text" name="aiap_lite_settings[sub_topics][${id}][title]" value="${title}" class="regular-text" style="width: 100%;">
          </div>
          <div style="flex: 2;">
            <input type="text" name="aiap_lite_settings[sub_topics][${id}][keywords]" value="${keywords}" class="regular-text" style="width: 100%;">
          </div>
          <div>
            <label>
              <input type="checkbox" name="aiap_lite_settings[sub_topics][${id}][enabled]" value="1" checked>
              有効
            </label>
          </div>
          <div>
            <button type="button" class="button button-link-delete delete-topic-btn">削除</button>
          </div>
        </div>
      `);

      $("#topics-list").append(newItem);
      $("#new-topic-title").val("");
      $("#new-topic-keywords").val("");
    });

    // 項目の削除
    $(document).on("click", ".delete-topic-btn", function () {
      if (confirm("この項目を削除してもよろしいですか？")) {
        $(this)
          .closest(".topic-item")
          .fadeOut(300, function () {
            $(this).remove();
          });
      }
    });
  }
});
