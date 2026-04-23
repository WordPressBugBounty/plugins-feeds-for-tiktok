import React from "react";

const plugins = [
    {
        id: 'instagram',
        title: 'Instagram',
		url: 'https://smashballoon.com/instagram-feed/?utm_campaign=instagram-pro&utm_source=block-feed-embed&utm_medium=did-you-know',
        socialWall: 'https://smashballoon.com/social-wall/pricing/?edd_license_key=%s&upgrade=true&utm_campaign=instagram-pro&utm_source=feed-type&utm_medium=social-wall&utm_content=upgrade',
    },
    {
        id: 'facebook',
        title: 'Facebook',
        url: 'https://smashballoon.com/custom-facebook-feed/?utm_campaign=facebook-pro&utm_source=block-feed-embed&utm_medium=did-you-know',
        socialWall: 'https://smashballoon.com/social-wall/pricing/?edd_license_key=%s&upgrade=true&utm_campaign=facebook-pro&utm_source=feed-type&utm_medium=social-wall&utm_content=upgrade'
    },
    {
        id: 'twitter',
        title: 'Twitter',
        url: 'https://smashballoon.com/custom-twitter-feeds/?utm_campaign=twitter-pro&utm_source=block-feed-embed&utm_medium=did-you-know',
        socialWall: 'https://smashballoon.com/social-wall/pricing/?edd_license_key=%s&upgrade=true&utm_campaign=twitter-pro&utm_source=feed-type&utm_medium=social-wall&utm_content=upgrade'
    },
    {
        id: 'youtube',
        title: 'YouTube',
        url: 'https://smashballoon.com/youtube-feed/?utm_campaign=youtube-pro&utm_source=block-feed-embed&utm_medium=did-you-know',
        socialWall: 'https://smashballoon.com/social-wall/pricing/?edd_license_key=%s&upgrade=true&utm_campaign=youtube-pro&utm_source=feed-type&utm_medium=social-wall&utm_content=upgrade'
    },
    {
        id: 'reviews',
        title: 'Reviews',
        url: 'https://smashballoon.com/reviews-feed/?utm_campaign=reviews-pro&utm_source=block-feed-embed&utm_medium=did-you-know',
        socialWall: 'https://smashballoon.com/social-wall/pricing/?edd_license_key=%s&upgrade=true&utm_campaign=reviews-pro&utm_source=feed-type&utm_medium=social-wall&utm_content=upgrade'
    },
];

const CreateFeedUpsell = ({orCreateFeed}) => {
    orCreateFeed = orCreateFeed?.toString() || '';
    
    const replacedFeed = plugins.reduce((acc, plugin) => {
        const { title, url } = plugin;
        const replaced = acc.replace(title, `<a href="${url}" target='_blank'>${title}</a>`);
        return replaced;
    }, orCreateFeed);

    return <span dangerouslySetInnerHTML={{ __html: replacedFeed }} />;
}

const ProUpsell = ({upsellDesc, id}) => {
    upsellDesc = upsellDesc?.toString() || '';
    const plugin = plugins.find(plugin => plugin.id === id);

    let replacedUpsell = upsellDesc.replace('Social Wall plugin', 
        `<a href="${plugin.socialWall}" target='_blank'>Social Wall plugin</a>`
    );
    replacedUpsell = replacedUpsell.replace('Pro version',
        `<a href="${plugin.url}" target='_blank'>Pro version</a>`
    );

    return <span dangerouslySetInnerHTML={{ __html: replacedUpsell }} />;
}

export {CreateFeedUpsell, ProUpsell};
