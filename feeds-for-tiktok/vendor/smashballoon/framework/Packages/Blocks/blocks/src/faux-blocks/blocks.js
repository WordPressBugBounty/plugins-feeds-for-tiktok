import { __ } from "@wordpress/i18n";
import React from "react";
import { FacebookIcon, InstagramIcon, YoutubeIcon, TwitterIcon, ReviewIcon,
    InstagramFauxPreview, FacebookFauxPreview, TwitterFauxPreview, YoutubeFauxPreview,
    ReviewFauxPreview, 
    ReviewLogo} from "../icons";

const fauxBlocks = [
    {
        id: 'instagram',
        name: 'smashballoon/instagram-feed-faux',
        title: __('Instagram Feed', 'instagram-feed'),
        icon: 'instagram',
        description: __('Select and display any of your Instagram feeds.', 'instagram-feed'),
        contentDesc: __('Custom Instagram Feeds is a highly customizable way to display feeds from your Instagram account. Promote your latest content and update your site content automatically.', 'instagram-feed'),
        downloadLink: 'https://downloads.wordpress.org/plugin/instagram-feed.zip',
        logo: <InstagramIcon />,
        preview: <InstagramFauxPreview />,
    },
    {
        id: 'facebook',
        name: 'smashballoon/facebook-feed-faux',
        title: __('Facebook Feed', 'instagram-feed'),
        icon: 'facebook',
        description: __('Select and display any of your Facebook feeds.', 'instagram-feed'),
        contentDesc: __('Custom Facebook Feeds is a highly customizable way to display feeds from your Facebook page. Promote your latest content and update your site content automatically.', 'instagram-feed'),
        downloadLink: 'https://downloads.wordpress.org/plugin/custom-facebook-feed.zip',
        logo: <FacebookIcon />,
        preview: <FacebookFauxPreview />,
    },
    {
        id: 'youtube',
        name: 'smashballoon/youtube-feed-faux',
        title: __('YouTube Feed', 'instagram-feed'),
        icon: 'youtube',
        description: __('Select and display any of your Youtube feeds.', 'instagram-feed'),
        contentDesc: __('YouTube Feeds is a highly customizable way to display feeds from your YouTube channel. Promote your latest content and update your site content automatically.', 'instagram-feed'),
        downloadLink: 'https://downloads.wordpress.org/plugin/feeds-for-youtube.zip',
        logo: <YoutubeIcon />,
        preview: <YoutubeFauxPreview />,
    },
    {
        id: 'twitter',
        name: 'smashballoon/twitter-feed-faux',
        title: __('Twitter Feed', 'instagram-feed'),
        icon: 'twitter',
        description: __('Select and display any of your Twitter feeds.', 'instagram-feed'),
        contentDesc: __('Custom Twitter Feeds is a highly customizable way to display tweets from your Twitter account. Promote your latest content and update your site content automatically.', 'instagram-feed'),
        downloadLink: 'https://downloads.wordpress.org/plugin/custom-twitter-feeds.zip',
        logo: <TwitterIcon />,
        preview: <TwitterFauxPreview />,
    },
    {
        id: 'reviews',
        name: 'smashballoon/reviews-feed-faux',
        title: __('Reviews Feed', 'instagram-feed'),
        icon: <ReviewLogo />,
        description: __('Select and display any of your Reviews feeds.', 'instagram-feed'),
        contentDesc: __('Reviews Feeds is a highly customizable way to display reviews from Google or Yelp. Promote your latest content and update your site content automatically.', 'instagram-feed'),
        downloadLink: 'https://downloads.wordpress.org/plugin/reviews-feed.zip',
        logo: <ReviewIcon />,
        preview: <ReviewFauxPreview />,
    }
];

export {fauxBlocks};
